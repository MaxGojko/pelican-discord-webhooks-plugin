<?php

namespace JoanFo\DiscordWebhooks\Livewire;

use App\Enums\WebhookScope;
use App\Extensions\Webhooks\WebhookPreview;
use App\Models\WebhookConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use JoanFo\DiscordWebhooks\Support\EmbedColor;
use JoanFo\DiscordWebhooks\Support\PayloadRenderer;
use JoanFo\DiscordWebhooks\Support\Url;

class DiscordPreview extends WebhookPreview
{
    private const CSS_FILE = __DIR__ . '/../../css/discord-preview.css';

    /** Stylesheet contents, read once per process. */
    private static ?string $css = null;

    /**
     * Substitutes inside each string value rather than in the JSON text, so sample or event
     * values with quotes or newlines cannot break the preview. Not sanitised on purpose: the
     * form state still holds `#hex` colours and `has_timestamp`, which processEmbeds() reads.
     *
     * @return array<string, mixed>
     */
    protected function resolvePayload(): array
    {
        $payload = $this->formPayload ?? $this->record?->payload;

        if (blank($payload) || !is_array($payload)) {
            return [];
        }

        $sampleData = $this->resolveScope() === WebhookScope::Server
            ? WebhookConfiguration::getServerWebhookSampleData()
            : WebhookConfiguration::getWebhookSampleData();

        return PayloadRenderer::render($payload, $sampleData);
    }

    public function render(): View
    {
        return view('discord-webhooks::livewire.discord-preview', [
            ...$this->getViewData(),
            'css' => self::stylesheet(),
        ]);
    }

    /**
     * The stylesheet is rendered inline through the view's @assets block, so nothing has to be
     * copied into the panel's public directory.
     */
    private static function stylesheet(): string
    {
        if (self::$css === null) {
            $css = is_file(self::CSS_FILE) ? file_get_contents(self::CSS_FILE) : false;

            // The contents go into a <style> element unescaped, so make sure they cannot close it.
            self::$css = $css === false ? '' : str_ireplace('</style', '<\\/style', $css);
        }

        return self::$css;
    }

    /**
     * @return array{
     *     content: string|null,
     *     sender: array{name: string, avatar: string},
     *     embeds: array<int, mixed>,
     *     getTime: callable
     * }
     */
    public function getViewData(): array
    {
        $data = $this->resolvePayload();

        return [
            'content' => $this->text(data_get($data, 'content')),
            'sender' => [
                'name' => $this->text(data_get($data, 'username')) ?? 'Pelican',
                'avatar' => $this->safeUrl(data_get($data, 'avatar_url')) ?? asset('pelican.svg'),
            ],
            'embeds' => $this->processEmbeds(data_get($data, 'embeds', [])),
            'getTime' => fn () => now()->format('H:i'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function processEmbeds(mixed $embeds): array
    {
        return collect($embeds)
            ->filter(fn (mixed $embed) => is_array($embed))
            ->take(10)
            ->map(function (array $embed): array {
                if (!isset($embed['timestamp']) && !empty($embed['has_timestamp'])) {
                    $embed['timestamp'] = now()->toIso8601String();
                }

                if (isset($embed['timestamp'])) {
                    try {
                        $embed['timestamp'] = Carbon::parse($embed['timestamp'])->format('M j, Y H:i');
                    } catch (\Throwable) {
                        unset($embed['timestamp']);
                    }
                }

                $embed['view'] = [
                    'author_name' => $this->text($embed['author']['name'] ?? null),
                    'author_url' => $this->safeUrl($embed['author']['url'] ?? null),
                    'author_icon' => $this->safeUrl($embed['author']['icon_url'] ?? null),
                    'title' => $this->text($embed['title'] ?? null),
                    'title_url' => $this->safeUrl($embed['url'] ?? null),
                    'description' => $this->text($embed['description'] ?? null),
                    'fields' => $this->processFields($embed['fields'] ?? []),
                    'image' => $this->safeUrl($embed['image']['url'] ?? null),
                    'thumbnail' => $this->safeUrl($embed['thumbnail']['url'] ?? null),
                    'footer_text' => $this->text($embed['footer']['text'] ?? null),
                    'footer_icon' => $this->safeUrl($embed['footer']['icon_url'] ?? null),
                    'timestamp' => $embed['timestamp'] ?? null,
                    'color_style' => 'border-left-color: ' . (EmbedColor::toCss($embed['color'] ?? null) ?? '#1e1f22'),
                ];

                return $embed;
            })
            ->values()
            ->all();
    }

    /** @return array<int, array{name: string, value: string, inline: bool}> */
    private function processFields(mixed $fields): array
    {
        return collect(is_array($fields) ? $fields : [])
            ->filter(fn (mixed $field) => is_array($field))
            ->map(fn (array $field): array => [
                'name' => $this->text($field['name'] ?? null) ?? '',
                'value' => $this->text($field['value'] ?? null) ?? '',
                'inline' => !empty($field['inline']),
            ])
            ->values()
            ->all();
    }

    /**
     * Only scalar values are rendered as text, so a malformed payload (an array where a string
     * is expected) cannot break the escaping in the view.
     */
    private function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private function safeUrl(mixed $url): ?string
    {
        return Url::isHttp($url) ? $url : null;
    }
}
