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
      max-width: 560px;
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
          @foreach($bodyLines as $line)
            <p>{!! $line !!}</p>
          @endforeach
        </div>
        @if(!empty($action) && isset($action['url']) && isset($action['text']))
          <div class="cta-container">
            <a href="{{ $action['url'] }}" class="btn">{{ $action['text'] }}</a>
          </div>
        @endif
      </div>
      <div class="footer">
        © 2026 ScholarlyNest. All rights reserved.<br>
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
