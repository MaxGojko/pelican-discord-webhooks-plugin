<?php

namespace JoanFo\DiscordWebhooks\Schemas;

use App\Enums\TablerIcon;
use App\Enums\WebhookScope;
use App\Extensions\Webhooks\Schemas\BaseSchema;
use App\Models\WebhookConfiguration;
use BackedEnum;
use Closure;
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
use JoanFo\DiscordWebhooks\Livewire\DiscordPreview;
use JoanFo\DiscordWebhooks\Support\Color;
use JoanFo\DiscordWebhooks\Support\DiscordEndpoint;
use JoanFo\DiscordWebhooks\Support\DiscordPayload;
use JoanFo\DiscordWebhooks\Support\PayloadRenderer;
use JoanFo\DiscordWebhooks\Support\Url;

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
    private const PAYLOAD_FIELDS = DiscordPayload::FIELDS;

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
        return DiscordEndpoint::matches($endpoint);
    }

    /**
     * Discord's documented limits, enforced so the API rejects a payload the
     * endpoint would reject anyway. Keyed relative to `payload`, and matching what
     * mutateFormDataBeforeSave() produces.
     *
     * @return array<string, mixed>
     */
    public function getPayloadRules(): array
    {
        $url = self::httpUrlRule();

        return [
            'content' => ['nullable', 'string', 'max:2000'],
            'username' => ['nullable', 'string', 'max:80'],
            'avatar_url' => ['nullable', 'string', $url],
            'thread_name' => ['nullable', 'string', 'max:100'],
            'flags' => ['nullable', 'integer', 'min:0'],
            'allowed_mentions' => ['nullable', 'array'],
            'allowed_mentions.parse' => ['nullable', 'array'],
            'allowed_mentions.parse.*' => ['string', 'in:' . implode(',', DiscordPayload::MENTION_TYPES)],
            'embeds' => ['nullable', 'array', 'max:10'],
            'embeds.*' => ['array'],
            'embeds.*.title' => ['nullable', 'string', 'max:256'],
            'embeds.*.description' => ['nullable', 'string', 'max:4096'],
            'embeds.*.url' => ['nullable', 'string', $url],
            'embeds.*.color' => ['nullable', 'integer', 'min:0', 'max:' . Color::MAX],
            'embeds.*.has_timestamp' => ['nullable', 'boolean'],
            'embeds.*.author' => ['nullable', 'array'],
            'embeds.*.author.name' => ['nullable', 'string', 'max:256'],
            'embeds.*.author.url' => ['nullable', 'string', $url],
            'embeds.*.author.icon_url' => ['nullable', 'string', $url],
            'embeds.*.image.url' => ['nullable', 'string', $url],
            'embeds.*.thumbnail.url' => ['nullable', 'string', $url],
            'embeds.*.footer' => ['nullable', 'array'],
            'embeds.*.footer.text' => ['nullable', 'string', 'max:2048'],
            'embeds.*.footer.icon_url' => ['nullable', 'string', $url],
            'embeds.*.fields' => ['nullable', 'array', 'max:25'],
            'embeds.*.fields.*.name' => ['required', 'string', 'max:256'],
            'embeds.*.fields.*.value' => ['required', 'string', 'max:1024'],
            'embeds.*.fields.*.inline' => ['nullable', 'boolean'],
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
                        ->maxLength(80)
                        ->label(trans('discord-webhooks::discord.message.username')),
                    $this->urlInput('avatar_url', 'discord-webhooks::discord.message.avatar_url'),
                ]),
            Section::make(trans('discord-webhooks::discord.message.message'))
                ->collapsible()
                ->columnSpanFull()
                ->schema([
                    Textarea::make('content')
                        ->label(trans('discord-webhooks::discord.message.message'))
                        ->live(debounce: 500)
                        ->rows(4)
                        ->maxLength(2000)
                        ->required(fn (Get $get) => blank($get('embeds'))),
                    TextInput::make('thread_name')
                        ->label(trans('discord-webhooks::discord.message.forum_thread'))
                        ->maxLength(100),
                    CheckboxList::make('flags')
                        ->label(trans('discord-webhooks::discord.embed.flags'))
                        ->options([
                            self::SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.suppress_embeds'),
                            self::SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.suppress_notifications'),
                        ])
                        ->descriptions([
                            self::SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.suppress_embeds_text'),
                            self::SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.suppress_notifications_text'),
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
                ->required(fn (Get $get) => blank($get('content')))
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
                                ->maxLength(256)
                                ->required(fn (Get $get) => filled($get('author.url')) || filled($get('author.icon_url'))),
                            $this->urlInput('author.url', 'discord-webhooks::discord.embed.author_url'),
                            $this->urlInput('author.icon_url', 'discord-webhooks::discord.embed.author_icon_url'),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.body'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('title')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.title'))
                                ->maxLength(256)
                                ->required(fn (Get $get) => blank($get('description'))),
                            Textarea::make('description')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.body'))
                                ->maxLength(4096)
                                ->required(fn (Get $get) => blank($get('title'))),
                            ColorPicker::make('color')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.color'))
                                ->hex()
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (filled($value) && Color::fromHex($value) === null) {
                                        $fail(trans('discord-webhooks::discord.validation.color'));
                                    }
                                }),
                            $this->urlInput('url', 'discord-webhooks::discord.embed.url'),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.images'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            $this->urlInput('image.url', 'discord-webhooks::discord.embed.image_url'),
                            $this->urlInput('thumbnail.url', 'discord-webhooks::discord.embed.image_thumbnail'),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.footer'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('footer.text')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.footer'))
                                ->maxLength(2048)
                                ->required(fn (Get $get) => filled($get('footer.icon_url'))),
                            Checkbox::make('has_timestamp')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.has_timestamp')),
                            $this->urlInput('footer.icon_url', 'discord-webhooks::discord.embed.footer_icon_url'),
                        ]),
                    Section::make(trans('discord-webhooks::discord.embed.fields'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Repeater::make('fields')
                                ->reorderable()
                                ->addActionLabel(trans('discord-webhooks::discord.embed.add_field'))
                                ->collapsible()
                                ->maxItems(25)
                                ->schema([
                                    TextInput::make('name')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_name'))
                                        ->maxLength(256)
                                        ->required(),
                                    Textarea::make('value')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_value'))
                                        ->rows(4)
                                        ->maxLength(1024)
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
        // The API sends a ready made payload and none of the form fields, so there is
        // nothing to collapse and rebuilding it from the missing fields would empty it
        if (is_array($data['payload'] ?? null) && array_intersect_key($data, array_flip(self::PAYLOAD_FIELDS)) === []) {
            return $data;
        }

        $payload = DiscordPayload::fromForm($data);

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
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        unset($data['payload'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        return array_merge($data, DiscordPayload::toForm($payload));
    }

    /**
     * A text input for a URL that must be http(s) but may still hold `{{variable}}`
     * placeholders, which are replaced with event data only when the webhook fires.
     */
    private function urlInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->live(debounce: 500)
            ->label(trans($label))
            ->rule(fn (): Closure => self::httpUrlRule());
    }

    /**
     * Laravel validation closure accepting an http(s) URL. Values containing a
     * `{{variable}}` placeholder are accepted as they are, since the final URL is not
     * known until an event fills them in.
     */
    private static function httpUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value) || (is_string($value) && str_contains($value, '{{'))) {
                return;
            }

            if (!Url::isHttp($value)) {
                $fail(trans('discord-webhooks::discord.validation.url'));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    public function preparePayload(WebhookConfiguration $webhookConfiguration, array $eventData): array
    {
        $payload = $webhookConfiguration->payload;

        return PayloadRenderer::prepare(is_array($payload) ? $payload : [], $eventData, now());
    }
}
