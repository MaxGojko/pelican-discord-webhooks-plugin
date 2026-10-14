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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Http\Client\Response;
use JoanFo\DiscordWebhooks\Livewire\DiscordPreview;
use JoanFo\DiscordWebhooks\Support\Color;
use JoanFo\DiscordWebhooks\Support\Discord;
use JoanFo\DiscordWebhooks\Support\DiscordEndpoint;
use JoanFo\DiscordWebhooks\Support\DiscordPayload;
use JoanFo\DiscordWebhooks\Support\PayloadRenderer;
use JoanFo\DiscordWebhooks\Support\Url;

class DiscordSchema extends BaseSchema
{
    private const TOO_MANY_REQUESTS = 429;

    /** Used when Discord rate limits without saying for how long */
    private const DEFAULT_RETRY_SECONDS = 5;

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

    /**
     * The live form state the preview receives. Thread name, flags, mentions and the hidden
     * `_extra` data do not change what a message looks like, and an embed's own `_extra`
     * (its timestamp) travels inside `embeds`.
     *
     * @return string[]
     */
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
        $snowflake = 'regex:/\A\d{1,20}\z/';

        return [
            'content' => ['nullable', 'string', 'max:' . Discord::LIMIT_CONTENT],
            'username' => ['nullable', 'string', 'max:' . Discord::LIMIT_USERNAME, self::usernameRule()],
            'avatar_url' => ['nullable', 'string', $url],
            'tts' => ['nullable', 'boolean'],
            'thread_name' => ['nullable', 'string', 'max:' . Discord::LIMIT_THREAD_NAME],
            'applied_tags' => ['nullable', 'array', 'max:' . Discord::MAX_APPLIED_TAGS],
            'applied_tags.*' => [$snowflake],
            'flags' => ['nullable', 'integer', 'min:0'],
            'allowed_mentions' => ['nullable', 'array'],
            'allowed_mentions.parse' => ['nullable', 'array'],
            'allowed_mentions.parse.*' => ['string', 'in:' . implode(',', Discord::MENTION_TYPES)],
            'allowed_mentions.users' => ['nullable', 'array', 'max:' . Discord::MAX_MENTION_IDS],
            'allowed_mentions.users.*' => [$snowflake],
            'allowed_mentions.roles' => ['nullable', 'array', 'max:' . Discord::MAX_MENTION_IDS],
            'allowed_mentions.roles.*' => [$snowflake],
            'allowed_mentions.replied_user' => ['nullable', 'boolean'],
            'embeds' => ['nullable', 'array', 'max:' . Discord::MAX_EMBEDS],
            'embeds.*' => ['array'],
            'embeds.*.title' => ['nullable', 'string', 'max:' . Discord::LIMIT_EMBED_TITLE],
            'embeds.*.description' => ['nullable', 'string', 'max:' . Discord::LIMIT_EMBED_DESCRIPTION],
            'embeds.*.url' => ['nullable', 'string', $url],
            'embeds.*.timestamp' => ['nullable', 'string', 'date'],
            'embeds.*.color' => ['nullable', 'integer', 'min:0', 'max:' . Color::MAX],
            'embeds.*.has_timestamp' => ['nullable', 'boolean'],
            'embeds.*.author' => ['nullable', 'array'],
            'embeds.*.author.name' => ['nullable', 'string', 'max:' . Discord::LIMIT_AUTHOR_NAME],
            'embeds.*.author.url' => ['nullable', 'string', $url],
            'embeds.*.author.icon_url' => ['nullable', 'string', $url],
            'embeds.*.image.url' => ['nullable', 'string', $url],
            'embeds.*.thumbnail.url' => ['nullable', 'string', $url],
            'embeds.*.footer' => ['nullable', 'array'],
            'embeds.*.footer.text' => ['nullable', 'string', 'max:' . Discord::LIMIT_FOOTER_TEXT],
            'embeds.*.footer.icon_url' => ['nullable', 'string', $url],
            'embeds.*.fields' => ['nullable', 'array', 'max:' . Discord::MAX_FIELDS],
            'embeds.*.fields.*.name' => ['required', 'string', 'max:' . Discord::LIMIT_FIELD_NAME],
            'embeds.*.fields.*.value' => ['required', 'string', 'max:' . Discord::LIMIT_FIELD_VALUE],
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
                        ->maxLength(Discord::LIMIT_USERNAME)
                        ->rule(fn (): Closure => self::usernameRule())
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
                        ->maxLength(Discord::LIMIT_CONTENT)
                        ->required(fn (Get $get) => blank($get('embeds'))),
                    TextInput::make('thread_name')
                        ->label(trans('discord-webhooks::discord.message.forum_thread'))
                        ->helperText(trans('discord-webhooks::discord.message.forum_thread_text'))
                        ->maxLength(Discord::LIMIT_THREAD_NAME),
                    CheckboxList::make('flags')
                        ->label(trans('discord-webhooks::discord.embed.flags'))
                        ->options([
                            Discord::FLAG_SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.suppress_embeds'),
                            Discord::FLAG_SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.suppress_notifications'),
                        ])
                        ->descriptions([
                            Discord::FLAG_SUPPRESS_EMBEDS => trans('discord-webhooks::discord.message.suppress_embeds_text'),
                            Discord::FLAG_SUPPRESS_NOTIFICATIONS => trans('discord-webhooks::discord.message.suppress_notifications_text'),
                        ]),
                    CheckboxList::make('allowed_mentions')
                        ->label(trans('discord-webhooks::discord.embed.allowed_mentions'))
                        ->options([
                            'roles' => trans('discord-webhooks::discord.embed.roles'),
                            'users' => trans('discord-webhooks::discord.embed.users'),
                            'everyone' => trans('discord-webhooks::discord.embed.everyone'),
                        ]),
                    // Payload data without a field of its own (tts, tags, mention ids), kept so
                    // saving the form does not delete what an API client stored
                    Hidden::make(DiscordPayload::EXTRA),
                ]),
            Repeater::make('embeds')
                ->live(debounce: 500)
                ->itemLabel(fn (array $state) => $state['title'] ?? '')
                ->addActionLabel(trans('discord-webhooks::discord.embed.add_embed'))
                ->required(fn (Get $get) => blank($get('content')))
                ->reorderable()
                ->columnSpanFull()
                ->collapsible()
                ->maxItems(Discord::MAX_EMBEDS)
                ->schema([
                    Section::make(trans('discord-webhooks::discord.embed.author'))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextInput::make('author.name')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.author'))
                                ->maxLength(Discord::LIMIT_AUTHOR_NAME)
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
                                ->maxLength(Discord::LIMIT_EMBED_TITLE)
                                ->required(fn (Get $get) => blank($get('description'))),
                            Textarea::make('description')
                                ->live(debounce: 500)
                                ->label(trans('discord-webhooks::discord.embed.body'))
                                ->maxLength(Discord::LIMIT_EMBED_DESCRIPTION)
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
                                ->maxLength(Discord::LIMIT_FOOTER_TEXT)
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
                                ->maxItems(Discord::MAX_FIELDS)
                                ->schema([
                                    TextInput::make('name')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_name'))
                                        ->maxLength(Discord::LIMIT_FIELD_NAME)
                                        ->required(),
                                    Textarea::make('value')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.field_value'))
                                        ->rows(4)
                                        ->maxLength(Discord::LIMIT_FIELD_VALUE)
                                        ->required(),
                                    Checkbox::make('inline')
                                        ->live(debounce: 500)
                                        ->label(trans('discord-webhooks::discord.embed.inline_field')),
                                ]),
                        ]),
                    // An embed's data without a field of its own (its timestamp)
                    Hidden::make(DiscordPayload::EXTRA),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mutateFormDataBeforeSave(array $data): array
    {
        // The API sends a ready made `payload` or only unrelated attributes, such as a PATCH
        // renaming the webhook. Rebuilding the payload from form fields that were never sent
        // would empty it, so only data that holds form fields is collapsed.
        if (!DiscordPayload::hasFormFields($data)) {
            return $data;
        }

        $payload = DiscordPayload::fromForm($data);

        foreach (DiscordPayload::FIELDS as $field) {
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
     * Laravel validation closure rejecting the names Discord refuses for a webhook
     * username, which delivery would otherwise silently replace with the webhook's name.
     */
    private static function usernameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && !Discord::isAllowedUsernameTemplate($value)) {
                $fail(trans('discord-webhooks::discord.validation.username', [
                    'words' => implode(', ', Discord::FORBIDDEN_USERNAME_WORDS),
                ]));
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
