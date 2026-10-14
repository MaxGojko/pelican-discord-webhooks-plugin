<?php

namespace JoanFo\DiscordWebhooks\Livewire;

use App\Enums\WebhookScope;
use App\Extensions\Webhooks\WebhookPreview;
use App\Models\WebhookConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use JoanFo\DiscordWebhooks\Support\Color;
use JoanFo\DiscordWebhooks\Support\DiscordPayload;
use JoanFo\DiscordWebhooks\Support\PayloadRenderer;

class DiscordPreview extends WebhookPreview
{
    private const CSS_FILE = __DIR__ . '/../../css/discord-preview.css';

    /** Stylesheet contents, read once per process. */
    private static ?string $css = null;

    /**
     * Exactly what would be delivered: the live form state is first collapsed the way saving
     * does (colours become integers, unticked checkboxes disappear), or the stored payload is
     * used, and then rendered and sanitised with the sample event data like a real delivery.
     * The preview therefore shows the default content, drops what Discord would drop and
     * truncates what Discord would reject.
     *
     * The live state only holds the keys getPreviewFields() asks for. The rest (thread name,
     * flags, mentions) has no visible effect on a message, so fromForm() just sees it empty.
     *
     * @return array<string, mixed>
     */
    protected function resolvePayload(): array
    {
        $payload = $this->formPayload !== null
            ? DiscordPayload::fromForm($this->formPayload)
            : $this->record?->payload;

        $sampleData = $this->resolveScope() === WebhookScope::Server
            ? WebhookConfiguration::getServerWebhookSampleData()
            : WebhookConfiguration::getWebhookSampleData();

        return PayloadRenderer::prepare(is_array($payload) ? $payload : [], $sampleData, now());
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
     * Everything in the prepared payload is already valid, limited and escaped for output
     * by the view, so this only reshapes it for display.
     *
     * @return array{
     *     content: string|null,
     *     sender: array{name: string, avatar: string},
     *     embeds: array<int, array<string, mixed>>,
     *     getTime: callable
     * }
     */
    protected function getViewData(): array
    {
        $data = $this->resolvePayload();

        return [
            'content' => $data['content'] ?? null,
            'sender' => [
                'name' => $data['username'] ?? 'Pelican',
                'avatar' => $data['avatar_url'] ?? asset('pelican.svg'),
            ],
            'embeds' => array_map($this->processEmbed(...), $data['embeds'] ?? []),
            'getTime' => fn () => now()->format('H:i'),
        ];
    }

    /**
     * @param  array<string, mixed>  $embed
     * @return array<string, mixed>
     */
    private function processEmbed(array $embed): array
    {
        $color = Color::toHex($embed['color'] ?? null);
        $timestamp = isset($embed['timestamp'])
            ? Carbon::parse($embed['timestamp'])->setTimezone(now()->getTimezone())->format('M j, Y H:i')
            : null;

        return [
            'author_name' => $embed['author']['name'] ?? null,
            'author_url' => $embed['author']['url'] ?? null,
            'author_icon' => $embed['author']['icon_url'] ?? null,
            'title' => $embed['title'] ?? null,
            'title_url' => $embed['url'] ?? null,
            'description' => $embed['description'] ?? null,
            'fields' => array_map(fn (array $field): array => [
                'name' => $field['name'],
                'value' => $field['value'],
                'inline' => ($field['inline'] ?? false) === true,
            ], $embed['fields'] ?? []),
            'image' => $embed['image']['url'] ?? null,
            'thumbnail' => $embed['thumbnail']['url'] ?? null,
            'footer_text' => $embed['footer']['text'] ?? null,
            'footer_icon' => $embed['footer']['icon_url'] ?? null,
            'timestamp' => $timestamp,
            'color_style' => 'border-left-color: ' . ($color ?? '#1e1f22'),
        ];
    }
}
