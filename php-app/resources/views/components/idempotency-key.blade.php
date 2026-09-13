{{--
    Every write form in this app needs a client-supplied idempotency key so
    App\Support\CommandLedger's own replay-detection (already built, already
    used correctly by the JSON API) actually engages for the human-facing
    UI too -- see docs/MIGRATION_MATRIX.md's "Duplicate-submission hardening"
    section for the full incident this closes (a red-team pass found every
    Blade *ViewController generating a fresh Str::uuid() per request instead
    of a stable one, silently defeating CommandLedger::prior() entirely).

    Str::uuid() here runs once, at the moment this form is rendered by a
    real GET request, and the value is then fixed inside the returned HTML.
    A double-click or a browser-back-then-resubmit replays that same
    already-rendered form, so the same key travels with it -- exactly the
    "stable per page load, changes on a genuine reload" semantic
    CommandLedger needs. A fresh GET (a real reload, or navigating back to
    this screen later) renders the component again and gets a new key, so a
    deliberate second submission is never mistaken for a replay of the
    first.
--}}
<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
