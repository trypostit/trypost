<?php

declare(strict_types=1);

namespace App\Actions\Automation\Node;

use App\DataTransferObjects\Automation\NodeRunResult;
use App\Enums\Automation\HttpMethod;
use App\Models\AutomationRun;
use App\Services\Automation\ExpressionResolver;
use App\Services\Brand\SafeHttpFetcher;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RunWebhookNode
{
    public function __construct(
        private ExpressionResolver $resolver,
        private SafeHttpFetcher $safeHttp,
    ) {}

    public function __invoke(AutomationRun $run, array $config): NodeRunResult
    {
        $context = $run->resolverContext();
        $url = $this->resolver->resolve((string) data_get($config, 'url', ''), $context);
        $method = strtoupper((string) data_get($config, 'method', HttpMethod::Post->value));

        if ($url === '') {
            return NodeRunResult::failed(__('automations.errors.webhook_missing_url'), [
                'reason' => 'missing_url',
            ]);
        }

        try {
            $this->safeHttp->guardAgainstSsrf($url);
        } catch (RuntimeException) {
            return NodeRunResult::failed(__('automations.errors.url_not_allowed'), [
                'reason' => 'url_not_allowed',
                'url' => $url,
            ]);
        }
        $headers = [];

        foreach ($config['headers'] ?? [] as $k => $v) {
            $headers[$k] = $this->resolver->resolve((string) $v, $context);
        }

        // Parse the template as JSON FIRST, then resolve placeholders in its
        // string leaves — so a value containing `"`/`&`/newlines can't corrupt
        // the JSON (the final json_encode escapes it).
        $template = (string) data_get($config, 'payload_template', '{}');
        $trimmedTemplate = trim($template);

        if ($trimmedTemplate === '' || $trimmedTemplate === 'null') {
            $payload = [];
        } else {
            $decodedTemplate = json_decode($template, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return NodeRunResult::failed(__('automations.errors.webhook_invalid_payload_json'), [
                    'reason' => 'invalid_payload_json',
                ]);
            }

            $payload = $this->resolver->resolveStructured($decodedTemplate ?? [], $context);
        }

        if ($run->is_dry_run) {
            return NodeRunResult::completed(output: [
                'webhook' => ['method' => $method, 'url' => $url, 'dry_run' => true],
            ]);
        }

        try {
            $response = Http::withHeaders($headers)
                ->withUserAgent(config('trypost.user_agent'))
                ->withOptions(['allow_redirects' => false])
                ->send($method, $url, ['json' => $payload]);
        } catch (Throwable $e) {
            return NodeRunResult::failed(__('automations.errors.webhook_request_failed'), [
                'reason' => 'request_failed',
                'message' => $e->getMessage(),
            ]);
        }

        if ($response->serverError()) {
            return NodeRunResult::failed(__('automations.errors.webhook_server_error'), [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
        }

        return NodeRunResult::completed(output: [
            'webhook' => [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ],
        ]);
    }
}
