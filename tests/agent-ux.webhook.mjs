import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { request as httpsRequest } from 'node:https'
import { isIP } from 'node:net'
import { createHmac, randomBytes, timingSafeEqual } from 'node:crypto'
import { spawn } from 'node:child_process'

// A real public HTTPS URL terminates at this synthetic-only controlled receiver.
// Ephemeral-host DNS answers come from a real public resolver. Original URL/public-IP checks, cURL pinning and TLS verification remain enabled.
export async function startWebhookReceiver() {
  let secret = '',
    first = true
  const received = [],
    processed = new Set(),
    route = '/' + randomBytes(24).toString('hex')
  const server = createServer(async (request, response) => {
    response.setHeader('X-ProjectBrief-QA', 'controlled')
    if (request.url !== route || request.method !== 'POST') {
      response.writeHead(404)
      response.end()
      return
    }
    try {
      const chunks = []
      let size = 0
      for await (const chunk of request) {
        size += chunk.length
        assert.ok(size <= 131072)
        chunks.push(chunk)
      }
      const raw = Buffer.concat(chunks),
        timestamp = request.headers['x-projectbrief-timestamp']
      assert.ok(secret && Math.abs(Date.now() / 1000 - Number(timestamp)) < 300)
      const expected = Buffer.from(
        'sha256=' +
          createHmac('sha256', secret)
            .update(timestamp + '.')
            .update(raw)
            .digest('hex'),
      )
      const actual = Buffer.from(request.headers['x-projectbrief-signature'] || '')
      assert.equal(actual.length, expected.length)
      assert.ok(timingSafeEqual(actual, expected))
      const event = JSON.parse(raw)
      const allowed = [
        'version',
        'id',
        'type',
        'occurredAt',
        'workspaceId',
        'projectId',
        'taskId',
        'agentId',
        'assignmentId',
        'runId',
        'state',
        'actor',
        'changes',
        'origin',
      ]
      assert.ok(Object.keys(event).every((key) => allowed.includes(key)))
      received.push({
        id: event.id,
        delivery: request.headers['x-projectbrief-delivery'],
        raw: raw.toString(),
        event,
      })
      if (first) {
        first = false
        response.writeHead(503)
        response.end()
        return
      }
      processed.add(event.id)
      response.writeHead(204)
      response.end()
    } catch {
      response.writeHead(400)
      response.end()
    }
  })
  await new Promise((resolve) => server.listen(0, '0.0.0.0', resolve))
  const name = 'project-brief-agent-ux-tunnel-' + process.pid
  const child = spawn(
    'docker',
    [
      'run',
      '--rm',
      '--name',
      name,
      'cloudflare/cloudflared:latest',
      'tunnel',
      '--no-autoupdate',
      '--url',
      `http://host.docker.internal:${server.address().port}`,
      '--protocol',
      'http2',
    ],
    { stdio: ['ignore', 'pipe', 'pipe'] },
  )
  let transcript = ''
  let hostname
  try {
    hostname = await new Promise((resolve, reject) => {
      const timer = setTimeout(
        () => reject(new Error('Temporary HTTPS receiver was not available')),
        55000,
      )
      const output = (data) => {
        transcript += data
        const match = transcript.match(/https:\/\/[a-z0-9]+(?:-[a-z0-9]+){2,}\.trycloudflare\.com/)
        if (match) {
          clearTimeout(timer)
          resolve(match[0])
        }
      }
      child.stdout.on('data', output)
      child.stderr.on('data', output)
      child.once('exit', (code) => {
        clearTimeout(timer)
        reject(new Error('Temporary HTTPS tunnel exited: ' + code))
      })
    })
  } catch (error) {
    child.kill('SIGTERM')
    await new Promise((resolve) => server.close(resolve))
    throw error
  }
  try {
    const addresses = []
    await new Promise((resolve) => setTimeout(resolve, 10000))
    for (const type of ['A', 'AAAA']) {
      let answer
      for (let attempt = 0; attempt < 20; attempt++) {
        const response = await fetch(
          'https://cloudflare-dns.com/dns-query?name=' +
            encodeURIComponent(new URL(hostname).hostname) +
            '&type=' +
            type,
          {
            headers: { accept: 'application/dns-json' },
            redirect: 'error',
            signal: AbortSignal.timeout(10000),
          },
        )
        assert.ok(response.ok)
        answer = await response.json()
        if (answer.Status === 0) break
        await new Promise((resolve) => setTimeout(resolve, 3000))
      }
      assert.equal(answer.Status, 0, 'Public DNS did not become ready for ' + hostname)
      addresses.push(
        ...(answer.Answer || [])
          .filter((item) => [1, 28].includes(item.type))
          .map((item) => item.data),
      )
    }
    assert.ok(addresses.length && addresses.every((address) => isIP(address)))
    const publicRequest = (options = {}) =>
      new Promise((resolve, reject) => {
        const request = httpsRequest(
          hostname + route,
          {
            method: options.method || 'GET',
            headers: options.headers,
            lookup: (_host, config, callback) =>
              config.all
                ? callback(null, [{ address: addresses[0], family: isIP(addresses[0]) }])
                : callback(null, addresses[0], isIP(addresses[0])),
          },
          (response) => {
            const chunks = []
            response.on('data', (chunk) => chunks.push(chunk))
            response.on('end', () =>
              resolve({
                status: response.statusCode,
                headers: new Headers(response.headers),
                text: Buffer.concat(chunks).toString(),
              }),
            )
          },
        )
        request.setTimeout(8000, () => request.destroy(new Error('Receiver timeout')))
        request.on('error', reject)
        request.end(options.body)
      })
    return {
      url: hostname + route,
      received,
      processed,
      addresses,
      probe: () => publicRequest(),
      diagnostics() {
        return {
          connectorRegistered: transcript.includes('Registered tunnel connection'),
          connectorError: transcript.includes('ERR'),
          processExited: child.exitCode !== null,
        }
      },
      setSecret(value) {
        secret = value
      },
      async replayLast() {
        const previous = received.at(-1),
          timestamp = String(Math.floor(Date.now() / 1000))
        const signature =
          'sha256=' +
          createHmac('sha256', secret)
            .update(timestamp + '.')
            .update(previous.raw)
            .digest('hex')
        return publicRequest({
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-ProjectBrief-Timestamp': timestamp,
            'X-ProjectBrief-Signature': signature,
            'X-ProjectBrief-Delivery': previous.delivery,
          },
          body: previous.raw,
        })
      },
      async close() {
        child.kill('SIGTERM')
        const stop = spawn('docker', ['stop', name], { stdio: 'ignore' })
        await new Promise((resolve) => stop.once('close', resolve))
        await new Promise((resolve) => server.close(resolve))
      },
    }
  } catch (error) {
    child.kill('SIGTERM')
    const stop = spawn('docker', ['stop', name], { stdio: 'ignore' })
    await new Promise((resolve) => stop.once('close', resolve))
    await new Promise((resolve) => server.close(resolve))
    throw error
  }
}
