<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $subject }}</title>
  <style>
    body {
      font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background-color: #fafafa;
      color: #18181b;
      margin: 0;
      padding: 0;
      -webkit-font-smoothing: antialiased;
    }
    .wrapper {
      width: 100%;
      background-color: #fafafa;
      padding: 48px 0;
    }
    .container {
      max-width: 620px;
      margin: 0 auto;
      background-color: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 12px;
      padding: 40px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
    }
    .header {
      text-align: center;
      margin-bottom: 32px;
    }
    .logo {
      font-size: 20px;
      font-weight: 700;
      color: #18181b;
      text-decoration: none;
      letter-spacing: -0.025em;
      border-bottom: 2px solid #10b981;
      padding-bottom: 4px;
      display: inline-block;
    }
    .greeting {
      font-size: 18px;
      font-weight: 700;
      color: #18181b;
      margin-top: 0;
      margin-bottom: 16px;
    }
    .content-body {
      font-size: 14px;
      line-height: 24px;
      color: #3f3f46;
      margin-bottom: 24px;
    }
    .content-body p {
      margin-top: 0;
      margin-bottom: 16px;
    }
    .section-heading {
      margin: 28px 0 12px;
      padding-bottom: 8px;
      border-bottom: 1px solid #e4e4e7;
      color: #18181b;
      font-size: 13px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .details-card {
      margin: 14px 0 20px;
      padding: 16px 18px;
      border: 1px solid #e4e4e7;
      border-radius: 10px;
      background: #fafafa;
    }
    .details-title {
      margin: 0 0 12px;
      color: #18181b;
      font-size: 13px;
      font-weight: 800;
    }
    .detail-row {
      padding: 8px 0;
      border-top: 1px solid #e4e4e7;
    }
    .detail-row:first-of-type { border-top: 0; padding-top: 0; }
    .detail-row:last-child { padding-bottom: 0; }
    .detail-label {
      display: block;
      margin-bottom: 2px;
      color: #71717a;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .detail-value { color: #27272a; font-size: 14px; word-break: break-word; }
    .callout {
      margin: 18px 0;
      padding: 14px 16px;
      border-left: 4px solid #d97706;
      border-radius: 0 8px 8px 0;
      background: #fffbeb;
      color: #3f3f46;
    }
    .callout strong { display: block; margin-bottom: 4px; color: #92400e; }
    .bullet-list { margin: 10px 0 20px; padding: 0; list-style: none; }
    .bullet-item { position: relative; margin: 0; padding: 7px 0 7px 18px; color: #3f3f46; }
    .bullet-item::before { content: ""; position: absolute; left: 1px; top: 16px; width: 5px; height: 5px; border-radius: 50%; background: #d97706; }
    .bullet-label { font-weight: 700; color: #18181b; }
    .code-box {
      background-color: #f4f4f5;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 16px;
      text-align: center;
      margin: 28px 0;
    }
    .code-value {
      font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
      font-size: 32px;
      font-weight: 700;
      letter-spacing: 0.25em;
      color: #18181b;
    }
    .field-label {
      font-size: 11px;
      font-weight: bold;
      color: #71717a;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 4px;
      margin-top: 16px;
    }
    .field-value {
      font-size: 14px;
      color: #18181b;
      margin-bottom: 16px;
    }
    .message-box {
      background-color: #fafafa;
      border-left: 4px solid #e4e4e7;
      padding: 16px;
      font-style: italic;
      color: #52525b;
      margin: 16px 0;
    }
    .content-note { margin: 16px 0; color: #71717a; font-size: 12px; line-height: 20px; text-align: center; }
    .cta-container {
      text-align: center;
      margin: 32px 0;
    }
    .btn {
      display: inline-block;
      background-color: #18181b;
      color: #ffffff !important;
      text-decoration: none;
      padding: 12px 28px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.025em;
      text-transform: uppercase;
      box-shadow: 0 4px 6px -1px rgba(24, 24, 27, 0.15);
    }
    .footer {
      margin-top: 40px;
      border-top: 1px solid #e4e4e7;
      padding-top: 24px;
      text-align: center;
      font-size: 12px;
      color: #71717a;
    }
    .unsubscribe-link {
      color: #3b82f6;
      text-decoration: underline;
      display: inline-block;
      margin-top: 8px;
    }
    @media only screen and (max-width: 680px) {
      .wrapper { padding: 16px 0; }
      .container { margin: 0 12px; padding: 28px 20px; border-radius: 10px; }
      .code-value { font-size: 26px; }
      .btn { display: block; padding-left: 16px; padding-right: 16px; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="container">
      <div class="header">
        <span class="logo">ScholarlyNest</span>
      </div>
      <div class="content">
        @if(!empty($greeting))
          <h2 class="greeting">{{ $greeting }}</h2>
        @endif
        <div class="content-body">
          @php($blocks = $bodyBlocks ?? app(\App\Services\EmailContentFormatter::class)->format($bodyLines ?? []))
          @php($insideList = false)
          @foreach($blocks as $block)
            @if($block['type'] === 'list_item')
              @if(!$insideList)<ul class="bullet-list">@php($insideList = true)@endif
              <li class="bullet-item">
                @if(!empty($block['label']))<span class="bullet-label">{{ $block['label'] }}:</span>@endif
                {!! $block['value_html'] !!}
              </li>
              @continue
            @elseif($insideList)
              </ul>@php($insideList = false)
            @endif

            @if($block['type'] === 'heading')
              <div class="section-heading">{{ $block['text'] }}</div>
            @elseif($block['type'] === 'details')
              <div class="details-card">
                <div class="details-title">{{ $block['title'] }}</div>
                @if(!empty($block['rows']))
                  @foreach($block['rows'] as $row)
                    <div class="detail-row"><span class="detail-label">{{ $row['label'] }}</span><span class="detail-value">{{ $row['value'] }}</span></div>
                  @endforeach
                @else
                  <div class="detail-value">{!! $block['html'] !!}</div>
                @endif
              </div>
            @elseif($block['type'] === 'callout')
              <div class="callout"><strong>{{ $block['title'] }}</strong>{!! $block['html'] !!}</div>
            @elseif($block['type'] === 'quote')
              <div class="message-box">{!! $block['html'] !!}</div>
            @elseif($block['type'] === 'code')
              <div class="code-box"><div class="code-value">{{ $block['value'] }}</div></div>
            @elseif($block['type'] === 'note')
              <div class="content-note">{!! $block['html'] !!}</div>
            @else
              <p>{!! $block['html'] !!}</p>
            @endif
          @endforeach
          @if($insideList)</ul>@endif
        </div>
        @if(!empty($action) && isset($action['url']) && isset($action['text']))
          <div class="cta-container">
            <a href="{{ $action['url'] }}" class="btn">{{ $action['text'] }}</a>
          </div>
        @endif
      </div>
      <div class="footer">
        © {{ now()->year }} ScholarlyNest. All rights reserved.<br>
        Elevating academic publishing and research collaboration.
        @if(!empty($unsubscribeUrl))
          <br>
          <a href="{{ $unsubscribeUrl }}" class="unsubscribe-link">Unsubscribe from this list</a>
        @endif
      </div>
    </div>
  </div>
</body>
</html>
