<?php

namespace App\Console\Commands;

use App\Services\AiTaskAssistant;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;

class CheckAiBridge extends Command
{
    protected $signature = 'ai:bridge-check
        {--live : Send exactly one synthetic task through the configured Deno bridge}
        {--output= : Optional new JSON report file; existing files are never overwritten}';

    protected $description = 'Check the Groq bridge once with the task assistant schema and validator, without reading projects';

    public function handle(AiTaskAssistant $assistant): int
    {
        $output = $this->option('output');
        if ($output && (! is_dir(dirname($output)) || file_exists($output))) {
            $this->error('The output directory must exist and the output file must not already exist.');

            return self::INVALID;
        }
        if (! $this->option('live')) {
            $this->info('Plan only: one synthetic Groq bridge request. Use --live to send it. No network or database access.');

            return self::SUCCESS;
        }
        $input = ['title' => 'Фото товаров на телефоне', 'description' => 'Хочу, чтобы фотографии товаров были крупнее и их можно было листать на телефоне.', 'expectedResult' => '', 'section' => 'Каталог', 'location' => 'Карточка товара'];
        try {
            $result = $assistant->checkBridge($input);
        } catch (HttpResponseException $exception) {
            $this->error('Bridge check did not send a request: '.json_decode($exception->getResponse()->getContent(), true)['code']);

            return self::FAILURE;
        }
        $report = ['checkedAt' => now('UTC')->toIso8601String(), 'origin' => 'local CLI; not Jino', 'syntheticOnly' => true, 'requests' => 1, 'provider' => 'groq_bridge', 'model' => config('ai.providers.groq_bridge.model'), 'input' => $input, ...$result];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($output) {
            $handle = @fopen($output, 'x');
            if ($handle === false) {
                $this->error('Could not create output file.');

                return self::FAILURE;
            }
            $written = fwrite($handle, $json."\n");
            fclose($handle);
            if ($written !== strlen($json."\n")) {
                $this->error('Could not write the complete report.');

                return self::FAILURE;
            }
        } else {
            $this->line($json);
        }
        $this->line('HTTP '.($result['httpStatus'] ?? 'none').' | '.$result['elapsedMs'].' ms | schema '.(($result['schemaValid'] ?? false) ? 'valid' : 'not accepted'));
        if ($result['httpStatus'] === 403 || ($result['providerStatus'] ?? null) === 403) {
            $this->warn('Access denied by bridge/provider. No retries or alternate routes were attempted.');
        }

        return ($result['schemaValid'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
