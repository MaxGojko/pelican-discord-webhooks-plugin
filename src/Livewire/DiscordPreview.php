<?php

namespace JoanFo\DiscordWebhooks\Livewire;

use App\Extensions\Webhooks\WebhookPreview;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DiscordPreview extends WebhookPreview
{
    public function render(): View
    {
        return view('discord-webhooks::livewire.discord-preview', $this->getViewData());
    }

    /**
     * @return array{
     *     content: mixed,
     *     sender: array{name: string, avatar: string},
     *     embeds: array<int, mixed>,
     *     getTime: callable
     * }
     */
    public function getViewData(): array
    {
        $data = $this->resolvePayload();

        return [
            'content' => data_get($data, 'content'),
            'sender' => [
                'name' => filled(data_get($data, 'username')) ? data_get($data, 'username') : 'Pelican',
                'avatar' => filled(data_get($data, 'avatar_url')) ? data_get($data, 'avatar_url') : asset('pelican.svg'),
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
                $color = $embed['color'] ?? null;
                $embed['color'] = match (true) {
                    is_int($color) => '#' . str_pad(dechex($color), 6, '0', STR_PAD_LEFT),
                    is_string($color) => $color,
                    default => null,
                };

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
                    'author_name' => $embed['author']['name'] ?? null,
                    'author_url' => $this->safeUrl($embed['author']['url'] ?? null),
                    'author_icon' => $this->safeUrl($embed['author']['icon_url'] ?? null),
                    'title' => $embed['title'] ?? null,
                    'title_url' => $this->safeUrl($embed['url'] ?? null),
                    'description' => $embed['description'] ?? null,
                    'fields' => $embed['fields'] ?? [],
                    'image' => $this->safeUrl($embed['image']['url'] ?? null),
                    'thumbnail' => $this->safeUrl($embed['thumbnail']['url'] ?? null),
                    'footer_text' => $embed['footer']['text'] ?? null,
                    'footer_icon' => $this->safeUrl($embed['footer']['icon_url'] ?? null),
                    'timestamp' => $embed['timestamp'] ?? null,
                    'color_style' => $embed['color']
                        ? 'border-left-color: ' . $embed['color']
                        : 'border-left-color: #1e1f22',
                ];

                return $embed;
            })
            ->values()
            ->all();
    }

    private function safeUrl(?string $url): ?string
    {
        return ($url && preg_match('/^https?:\/\//i', $url)) ? $url : null;
    }
}
