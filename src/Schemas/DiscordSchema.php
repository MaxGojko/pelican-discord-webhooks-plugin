<?php

namespace JoanFo\DiscordWebhooks\Schemas;

use App\Enums\TablerIcon;
use App\Enums\WebhookScope;
use App\Extensions\Webhooks\Schemas\BaseSchema;
use App\Models\WebhookConfiguration;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use JoanFo\DiscordWebhooks\Livewire\DiscordPreview;

class DiscordSchema extends BaseSchema
{
    /** Discord flag: SUPPRESS_EMBEDS */
    private const SUPPRESS_EMBEDS = 1 << 2;

    /** Discord flag: SUPPRESS_NOTIFICATIONS */
    private const SUPPRESS_NOTIFICATIONS = 1 << 12;

    private const TOO_MANY_REQUESTS = 429;

    /** Used when Discord rate limits without saying for how long */
    private const DEFAULT_RETRY_SECONDS = 5;

    /** Fields collapsed into (and expanded back out of) the stored payload. */
    private const PAYLOAD_FIELDS = [
        'username',
        'avatar_url',
        'content',
        'image',
        'thumbnail',
        'embeds',
        'thread_name',
        'flags',
        'allowed_mentions',
    ];

    public function getId(): string
    {
        return 'discord';
    }

    public function getLabel(): string
    {
        return trans('discord-webhooks::discord.label');
    }

    public function getColor(): ?string
    {
        return 'blurple';
    }

    public function getIcon(): string|BackedEnum|null
    {
        return TablerIcon::BrandDiscord;
    }

    public function getPreviewComponent(): ?string
    {
        return DiscordPreview::class;
    }

    /** @return string[] */
    public function getPreviewFields(): array
    {
        return ['content', 'username', 'avatar_url', 'embeds'];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        return str($endpoint)->contains(['discord.com', 'discordapp.com']);
    }

    /**
     * Discord's documented limits, enforced so the API rejects a payload the
     * endpoint would reject anyway.
     *
     * @return array<string, mixed>
     */
    public function getPayloadRules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:2000'],
            'username' => ['nullable', 'string', 'max:80'],
            'avatar_url' => ['nullable', 'string', 'url'],
            'thread_name' => ['nullable', 'string', 'max:100'],
            'embeds' => ['nullable', 'array', 'max:10'],
            'embeds.*.title' => ['nullable', 'string', 'max:256'],
            'embeds.*.description' => ['nullable', 'string', 'max:4096'],
            'embeds.*.color' => ['nullable', 'integer', 'min:0', 'max:16777215'],
            'embeds.*.fields' => ['nullable', 'array', 'max:25'],
            'embeds.*.fields.*.name' => ['required', 'string', 'max:256'],
            'embeds.*.fields.*.value' => ['required', 'string', 'max:1024'],
            'embeds.*.footer.text' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * Discord rate limits webhooks and reports how long to wait, either in the standard
     * `Retry-After` header or as fractional seconds in the body.
     */
    public function retryAfter(Response $response): ?int
    {
        if ($response->status() !== self::TOO_MANY_REQUESTS) {
            return null;
        }

        $retryAfter = $response->header('Retry-After') ?: $response->json('retry_after');

        if (blank($retryAfter)) {
            return self::DEFAULT_RETRY_SECONDS;
        }

        return max(1, (int) ceil((float) $retryAfter));
    }

    /** @return Component[] */
    public function getFormComponents(WebhookScope $scope): array
    {
        return [
            Section::make(trans('discord-webhooks::discord.message.profile'))
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('username')
                        ->live(debounce: 500)
                        ->label(trans('discord-webhooks::discord.message.username')),
                    TextInput::make('avatar_url')
                        ->live(debounce: 500)
                        ->label(trans('discord-webhooks::discord.message.avatar_url')),
                ]),
            Section::make(trans('discord-webhooks::discord.message.message'))
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('content')
                        ->label(trans('discord-webhooks::discord.message.message'))
                        ->live(debounce: 500)
                        ->required(fn (Get $get) => empty($get('embeds'))),
                    TextInput::make('thread_name')
                        ->label(trans('discord-webhooks::discord.message.forum_thread')),
                    CheckboxList::make('flags')
                        ->label(trans('discord-webhooks::discord.embed.flags'))
                        ->options([
                            self::SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.supress_embeds'),
                            self::SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.supress_notifications'),
                        ])
                        ->descriptions([
                            self::SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.supress_embeds_text'),
                            self::SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.supress_notifications_text'),
                        ]),
                    CheckboxList::make('allowed_mentions')
                        ->label(trans('discord-webhooks::discord.embed.allowed_mentions'))
                        ->options([
                            'roles' => trans('discord-webhooks::discord.embed.roles'),
                            'users' => trans('discord-webhooks::discord.embed.users'),
                            'everyone' => trans('discord-webhooks::discord.embed.everyone'),
                        ]),
                ]),
            Repeater::make('embeds')
                ->live(debounce: 500)
                ->itemLabel(fn (array $state) => $state['title'] ?? '')
                ->addActionLabel(trans('discord-webhooks::discord.embed.add_embed'))
                ->required(fn (Get $get) => empty($get('content')))
                ->reorderable()
                ->columnSpanFull()
                ->collapsible()
                ->maxItems(10)
                ->schema([
                    Section::make(trans('discord-webhooks::discord.embed.author'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('author.name')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.author'))
                                ->required(fn (Get $get) => filled($get('author.url')) || filled($get('author.icon_url'))),
                            TextInput::make('author.url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.author_url')),
                            TextInput::make('author.icon_url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.author_icon_url')),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.body'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('title')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.title'))
                                ->required(fn (Get $get) => $get('description') === null),
                            Textarea::make('description')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.body'))
                                ->required(fn (Get $get) => $get('title') === null),
                            ColorPicker::make('color')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.color'))
                                ->hex(),
                            TextInput::make('url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.url')),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.images'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('image.url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.image_url')),
                            TextInput::make('thumbnail.url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.image_thumbnail')),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.footer'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('footer.text')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.footer')),
                            Checkbox::make('has_timestamp')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.has_timestamp')),
                            TextInput::make('footer.icon_url')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.footer_icon_url')),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.fields'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Repeater::make('fields')
                                ->reorderable()
                                ->addActionLabel(trans('discord-webhooks::discord.embed.add_field'))
                                ->collapsible()
                                ->schema([
                                    TextInput::make('name')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_name'))
                                        ->required(),
                                    Textarea::make('value')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_value'))
                                        ->rows(4)
                                        ->required(),
                                    Checkbox::make('inline')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.inline_field')),
                                ]),
                        ]),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateFormDataBeforeSave(array $data): array
    {
        $embeds = data_get($data, 'embeds', []);

        foreach ($embeds as &$embed) {
            $embed['color'] = hexdec(str_replace('#', '', (string) data_get($embed, 'color')));
            $embed = $this->filterEmpty($embed);
        }

        $selectedMentions = data_get($data, 'allowed_mentions', []);

        $payload = collect([
            'username' => data_get($data, 'username'),
            'avatar_url' => data_get($data, 'avatar_url'),
            'content' => data_get($data, 'content'),
            'image' => data_get($data, 'image'),
            'thumbnail' => data_get($data, 'thumbnail'),
            'embeds' => $embeds,
            'thread_name' => data_get($data, 'thread_name'),
            'flags' => collect(data_get($data, 'flags', []))->reduce(fn ($carry, $bit) => $carry | $bit, 0),
            'allowed_mentions' => $selectedMentions ? ['parse' => array_values($selectedMentions)] : [],
        ])->filter(fn ($value) => !empty($value))->all();

        foreach (self::PAYLOAD_FIELDS as $field) {
            unset($data[$field]);
        }

        $data['payload'] = $payload;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateFormDataBeforeFill(array $data): array
    {
        $embeds = data_get($data, 'payload.embeds', []);
        foreach ($embeds as &$embed) {
            $embed['color'] = '#' . dechex((int) data_get($embed, 'color'));
            $embed = $this->filterEmpty($embed);
        }

        $flags = (int) data_get($data, 'payload.flags');

        $fields = collect([
            'username' => data_get($data, 'payload.username'),
            'avatar_url' => data_get($data, 'payload.avatar_url'),
            'content' => data_get($data, 'payload.content'),
            'image' => data_get($data, 'payload.image'),
            'thumbnail' => data_get($data, 'payload.thumbnail'),
            'embeds' => $embeds,
            'thread_name' => data_get($data, 'payload.thread_name'),
            'flags' => collect([self::SUPPRESS_EMBEDS, self::SUPPRESS_NOTIFICATIONS])
                ->filter(fn (int $flag) => ($flags & $flag) !== 0)
                ->values(),
            'allowed_mentions' => data_get($data, 'payload.allowed_mentions.parse'),
        ])->filter(fn ($value) => !empty($value))->all();

        unset($data['payload'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        return array_merge($data, $fields);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    public function preparePayload(WebhookConfiguration $webhookConfiguration, array $eventData): array
    {
        $payload = json_encode($webhookConfiguration->payload) ?: '{}';
        $data = json_decode($webhookConfiguration->replaceVars($eventData, $payload), true) ?? [];

        $embeds = data_get($data, 'embeds');
        if ($embeds) {
            foreach ($embeds as &$embed) {
                if (data_get($embed, 'has_timestamp')) {
                    $embed['timestamp'] = Carbon::now();
                    unset($embed['has_timestamp']);
                }
            }

            $data['embeds'] = $embeds;

            // Embeds were requested, so never let SUPPRESS_EMBEDS hide them
            if (isset($data['flags'])) {
                $data['flags'] &= ~self::SUPPRESS_EMBEDS;
            }
        }

        if (isset($data['content']) && $data['content'] === '') {
            unset($data['content']);
        }

        return $data;
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<mixed, mixed>
     */
    private function filterEmpty(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_array($value) ? array_filter($value, fn ($nested) => !empty($nested)) : !empty($value))
            ->all();
    }
}
