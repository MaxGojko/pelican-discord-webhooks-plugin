<div>
    @assets
        {{-- Plugin-owned stylesheet (see DiscordPreview::stylesheet()), the only raw output in this view. --}}
        <style>{!! $css !!}</style>
    @endassets

    <div class="dc-chat">
        <div class="dc-msg">
            {{-- Avatar --}}
            <img src="{{ $sender['avatar'] }}" class="dc-avatar" alt="" referrerpolicy="no-referrer" loading="lazy">

            <div class="dc-msg-body">
                {{-- Meta row --}}
                <div class="dc-meta">
                    <span class="dc-username">{{ $sender['name'] }}</span>
                    <span class="dc-bot-tag">APP</span>
                    <span class="dc-timestamp">{{ $getTime() }}</span>
                </div>

                {{-- Message content --}}
                @if ($content !== null)
                    <div class="dc-content">{!! nl2br(e($content)) !!}</div>
                @endif

                {{-- Embeds --}}
                @foreach ($embeds as $embed)
                    <div class="dc-embed" style="{{ $embed['color_style'] }}">
                        <div class="dc-embed-body">
                            <div class="dc-embed-grid {{ $embed['thumbnail'] !== null ? 'has-thumbnail' : '' }}">

                                <div class="dc-embed-content">

                                    {{-- Author --}}
                                    @if ($embed['author_name'] !== null)
                                        <div class="dc-embed-author">
                                            @if ($embed['author_icon'] !== null)
                                                <img src="{{ $embed['author_icon'] }}" class="dc-embed-author-icon" alt="" referrerpolicy="no-referrer" loading="lazy">
                                            @endif
                                            @if ($embed['author_url'] !== null)
                                                <a href="{{ $embed['author_url'] }}" target="_blank" rel="noopener noreferrer" class="dc-embed-author-name dc-link">{{ $embed['author_name'] }}</a>
                                            @else
                                                <span class="dc-embed-author-name">{{ $embed['author_name'] }}</span>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Title --}}
                                    @if ($embed['title'] !== null)
                                        @if ($embed['title_url'] !== null)
                                            <a href="{{ $embed['title_url'] }}" target="_blank" rel="noopener noreferrer" class="dc-embed-title">{{ $embed['title'] }}</a>
                                        @else
                                            <div class="dc-embed-title">{{ $embed['title'] }}</div>
                                        @endif
                                    @endif

                                    {{-- Description --}}
                                    @if ($embed['description'] !== null)
                                        <div class="dc-embed-desc">{!! nl2br(e($embed['description'])) !!}</div>
                                    @endif

                                    {{-- Fields --}}
                                    @if ($embed['fields'] !== [])
                                        <div class="dc-embed-fields">
                                            @foreach ($embed['fields'] as $field)
                                                <div class="dc-embed-field {{ $field['inline'] ? 'inline' : '' }}">
                                                    <div class="dc-embed-field-name">{{ $field['name'] }}</div>
                                                    <div class="dc-embed-field-value">{!! nl2br(e($field['value'])) !!}</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                </div>

                                {{-- Thumbnail --}}
                                @if ($embed['thumbnail'] !== null)
                                    <img src="{{ $embed['thumbnail'] }}" class="dc-embed-thumbnail" alt="" referrerpolicy="no-referrer" loading="lazy">
                                @endif

                            </div>{{-- /.dc-embed-grid --}}

                            {{-- Large image --}}
                            @if ($embed['image'] !== null)
                                <img src="{{ $embed['image'] }}" class="dc-embed-image" alt="" referrerpolicy="no-referrer" loading="lazy">
                            @endif

                            {{-- Footer --}}
                            @if ($embed['footer_text'] !== null || $embed['timestamp'] !== null)
                                <div class="dc-embed-footer">
                                    @if ($embed['footer_icon'] !== null)
                                        <img src="{{ $embed['footer_icon'] }}" class="dc-embed-footer-icon" alt="" referrerpolicy="no-referrer" loading="lazy">
                                    @endif
                                    <span class="dc-embed-footer-text">
                                        @if ($embed['footer_text'] !== null){{ $embed['footer_text'] }}@endif
                                        @if ($embed['footer_text'] !== null && $embed['timestamp'] !== null)<span class="dc-embed-footer-sep">•</span>@endif
                                        @if ($embed['timestamp'] !== null){{ $embed['timestamp'] }}@endif
                                    </span>
                                </div>
                            @endif

                        </div>{{-- /.dc-embed-body --}}
                    </div>{{-- /.dc-embed --}}
                @endforeach

            </div>{{-- /.dc-msg-body --}}
        </div>{{-- /.dc-msg --}}
    </div>{{-- /.dc-chat --}}
</div>
