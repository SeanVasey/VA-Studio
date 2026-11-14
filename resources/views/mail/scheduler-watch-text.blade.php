VASEY.AUDIO

@if ($kind === 'stale')
The scheduler on {{ $siteHost }} has stopped recording its heartbeat.

Last heartbeat: {{ $lastHeartbeat ?? 'none has ever been recorded' }}@if ($ageSeconds !== null) ({{ $ageSeconds }} seconds ago)@endif

Stale since: {{ $episodeStart }}

Scheduled work (site releases, cache and export cleanup, contract render cleanup) is not running. Check the scheduler program on the host (supervisor, `schedule:work`) and the application database. You will get one message for this episode and one when the heartbeat resumes.
@else
The scheduler on {{ $siteHost }} is recording its heartbeat again.

Latest heartbeat: {{ $lastHeartbeat }}

The episode began at {{ $episodeStart }}. Scheduled work that was due meanwhile runs on the next ticks; check the admin health page for any command that failed.
@endif

This is an operator alert, not a customer message. It contains no customer data and no link.
