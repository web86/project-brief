import assert from 'node:assert/strict'
import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto'
import { createServer } from 'node:https'
import { spawn, spawnSync } from 'node:child_process'
import { readFile, writeFile } from 'node:fs/promises'
import path from 'node:path'

export async function runWebhookQa({ app, env, agentId, taskId }) {
  assert.equal(env.APP_ENV, 'testing')
  assert.ok(
    ['projectbrief_m96_browser_qa', 'projectbrief_m96_package_qa'].includes(env.DB_DATABASE),
  )
  const key = path.join(app, 'tests/receiver.key'),
    cert = path.join(app, 'tests/receiver.crt')
  const generated = spawnSync(
    'openssl',
    [
      'req',
      '-x509',
      '-newkey',
      'rsa:2048',
      '-nodes',
      '-days',
      '1',
      '-keyout',
      key,
      '-out',
      cert,
      '-subj',
      '/CN=agent.example.test',
      '-addext',
      'subjectAltName=DNS:agent.example.test',
    ],
    { stdio: 'ignore' },
  )
  assert.equal(generated.status, 0)
  const secret = 'whsec_' + randomBytes(32).toString('hex')
  const received = []
  const receiver = createServer(
    { key: await readFile(key), cert: await readFile(cert) },
    async (request, response) => {
      try {
        const chunks = []
        for await (const chunk of request) chunks.push(chunk)
        const body = Buffer.concat(chunks)
        const timestamp = request.headers['x-projectbrief-timestamp']
        assert.ok(Math.abs(Date.now() / 1000 - Number(timestamp)) < 300)
        const expected = Buffer.from(
          'sha256=' +
            createHmac('sha256', secret)
              .update(timestamp + '.')
              .update(body)
              .digest('hex'),
        )
        const actual = Buffer.from(request.headers['x-projectbrief-signature'] || '')
        assert.equal(actual.length, expected.length)
        assert.ok(timingSafeEqual(actual, expected))
        const event = JSON.parse(body)
        assert.equal(event.agentId, agentId)
        assert.equal(event.taskId, taskId)
        assert.deepEqual(
          Object.keys(event).sort(),
          [
            'version',
            'id',
            'type',
            'occurredAt',
            'workspaceId',
            'projectId',
            'taskId',
            'agentId',
            'assignmentId',
            'state',
          ].sort(),
        )
        received.push({
          delivery: request.headers['x-projectbrief-delivery'],
          body: body.toString(),
        })
        response.writeHead(received.length === 1 ? 503 : 204)
        response.end()
      } catch (error) {
        response.writeHead(400)
        response.end()
        received.push({ error: error.message })
      }
    },
  )
  await new Promise((resolve) => receiver.listen(0, '127.0.0.1', resolve))
  const port = receiver.address().port
  const fixture = path.join(app, 'tests/agent-webhook-qa.php')
  await writeFile(
    fixture,
    `<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Crypt;
use App\\Services\\WebhookDestination;
use App\\Services\\WebhookProcessor;
use App\\Services\\AgentAssignments;
use App\\Models\\ExternalAgent;
use App\\Models\\TaskAgentAssignment;
if (!app()->environment('testing') || !in_array(config('database.connections.mysql.database'), ['projectbrief_m96_browser_qa','projectbrief_m96_package_qa'], true)) { throw new RuntimeException('Disposable DB required'); }
// Test-only routing. Production WebhookDestination and TLS verification are unchanged.
$app->bind(WebhookDestination::class, fn () => new class extends WebhookDestination {
    public function resolve(string $url): array { return ['host'=>'agent.example.test','ip'=>'93.184.216.34']; }
});
Http::globalMiddleware(fn ($handler) => fn ($request, $options) => $handler($request, array_replace($options, ['verify' => getenv('QA_CA_CERT')])));
Http::globalOptions(['debug'=>fopen(getenv('QA_HTTP_DEBUG'),'w'), 'curl'=>[CURLOPT_CONNECT_TO=>['agent.example.test:443:127.0.0.1:${port}']]]);
$agent=ExternalAgent::where('uuid',getenv('QA_AGENT_ID'))->firstOrFail();
$row=TaskAgentAssignment::where('external_agent_id',$agent->id)->whereNull('revoked_at')->whereHas('task',fn($q)=>$q->where('uuid',getenv('QA_TASK_ID')))->firstOrFail();
$endpoint=DB::table('webhook_endpoints')->insertGetId(['uuid'=>(string) Illuminate\\Support\\Str::uuid(),'workspace_id'=>$agent->workspace_id,'external_agent_id'=>$agent->id,'name'=>'Local TLS QA','url'=>'https://agent.example.test/events','events'=>json_encode(['agent.task.assigned','agent.task.updated']),'enabled'=>true,'secret'=>Crypt::encryptString(getenv('QA_SIGNING_SECRET')),'secret_version'=>1,'project_filter'=>'all','created_at'=>now(),'updated_at'=>now()]);
AgentAssignments::event($row,'agent.task.assigned');
$processor=app(WebhookProcessor::class);
$processor->process();
$delivery=DB::table('webhook_deliveries')->where('webhook_endpoint_id',$endpoint)->first();
if ($delivery->http_status !== 503 || $delivery->status !== 'pending') { throw new RuntimeException('Expected real HTTP 503/retry, got '.json_encode([$delivery->http_status,$delivery->status,$delivery->error_code])); }
DB::table('webhook_deliveries')->where('id',$delivery->id)->update(['available_at'=>now()]);
$processor->process();
$delivery=DB::table('webhook_deliveries')->find($delivery->id);
if ($delivery->http_status !== 204 || $delivery->status !== 'succeeded' || $delivery->attempts !== 2) { throw new RuntimeException('Expected real HTTP 204 on retry'); }
AgentAssignments::event($row,'agent.task.updated');
$row->update(['revoked_at'=>now(),'state'=>'cancelled']);
$processor->process();
$cancelled=DB::table('webhook_deliveries')->where('webhook_endpoint_id',$endpoint)->where('status','cancelled')->where('error_code','agent_access_revoked')->count();
if ($cancelled !== 1) { throw new RuntimeException('Revoked delivery was not cancelled'); }
DB::table('webhook_endpoints')->where('id',$endpoint)->delete();
echo json_encode(['ok'=>true,'attempts'=>2,'cancelled'=>true]);
`,
  )
  try {
    const child = spawn('php', ['-d', 'curl.cainfo=' + cert, fixture], {
      cwd: app,
      env: {
        ...env,
        QA_AGENT_ID: agentId,
        QA_TASK_ID: taskId,
        QA_SIGNING_SECRET: secret,
        QA_CA_CERT: cert,
        QA_HTTP_DEBUG: path.join(app, 'tests/http-debug.log'),
        SSL_CERT_FILE: cert,
        CURL_CA_BUNDLE: cert,
      },
      stdio: ['ignore', 'pipe', 'pipe'],
    })
    let output = '',
      errors = ''
    child.stdout.on('data', (data) => (output += data))
    child.stderr.on('data', (data) => (errors += data))
    const code = await new Promise((resolve) => child.once('exit', resolve))
    assert.equal(code, 0, output + errors)
    const debug = (await readFile(path.join(app, 'tests/http-debug.log'), 'utf8').catch(() => ''))
      .split('\n')
      .filter((line) => /SSL|certificate|Trying|Could not|resolve|failed|connect/i.test(line))
      .join('\n')
    assert.match(output.trim(), /^\{/, output + errors + debug)
    assert.equal(JSON.parse(output).ok, true)
    assert.equal(received.length, 2)
    assert.ok(
      received.every((item) => !item.error),
      JSON.stringify(received.map((item) => item.error)),
    )
    assert.equal(received[0].delivery, received[1].delivery)
    assert.equal(received[0].body, received[1].body)
    return 'Real TLS webhook transport: HMAC/raw bytes, 503→204 retry with same delivery ID, revoked delivery cancelled'
  } finally {
    await new Promise((resolve) => receiver.close(resolve))
  }
}
