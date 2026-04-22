<h1>You have {{ count($applications) }} application{{ count($applications) === 1 ? '' : 's' }} due for follow-up</h1>

<ul>
@foreach($applications as $app)
  <li>
    <strong>{{ $app->canonicalJob?->title ?? '(job removed)' }}</strong> at {{ $app->canonicalJob?->company_name ?? '—' }}
    — <a href="{{ route('applications.show', $app) }}">Review</a>
  </li>
@endforeach
</ul>

<p>
  <a href="{{ route('applications.index') }}">Open your applications</a>
</p>
