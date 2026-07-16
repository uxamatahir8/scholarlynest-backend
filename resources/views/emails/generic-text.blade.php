{{ $greeting }}

@foreach($bodyLines ?? [] as $line)
{{ trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $line)))) }}

@endforeach
@if(!empty($action['url']))
{{ $action['text'] ?? 'Open Workflow' }}: {{ $action['url'] }}
@endif

ScholarlyNest
