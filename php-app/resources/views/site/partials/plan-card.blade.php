{{-- The open licence plan, straight from the database (App\Support\Site\PublicPlanCatalogue).
     Expects $plans; $compact drops the secondary link. If nothing is open for signup
     the card says so plainly instead of advertising something the form would reject. --}}
@php $plan = $plans[0] ?? null; @endphp
@if ($plan)
    @php
        $allowanceOrder = ['USER_SEATS', 'BRANCHES', 'ADVANCED_WORKFLOW', 'ANALYTICS', 'API_ACCESS'];
        $allowances = collect($plan['features'])
            ->filter(fn ($feature) => $feature['limit'] !== null && $feature['unit'] !== null)
            ->sortBy(fn ($feature) => ($position = array_search($feature['key'], $allowanceOrder, true)) === false ? count($allowanceOrder) : $position)
            ->values();
        $capabilities = collect($plan['features'])->reject(fn ($feature) => $feature['quota'])->values();
    @endphp
    <div class="plan">
        <div class="plan__head">
            <div>
                <span class="eyebrow">Pilot plan</span>
                <h3 style="margin-top:14px">{{ $plan['name'] }}</h3>
                <p class="plan__sub">What your organisation receives once its application is approved.</p>
            </div>
            <span class="chip chip--warn">Pilot</span>
        </div>

        @if ($allowances->isNotEmpty())
            <div class="allowances">
                @foreach ($allowances as $allowance)
                    <div class="allowance"><b>{{ number_format($allowance['limit']) }}</b><small>{{ $allowance['unit'] }}</small></div>
                @endforeach
            </div>
        @endif

        @if ($capabilities->isNotEmpty())
            <ul class="features">
                @foreach ($capabilities as $capability)
                    <li>
                        <i><x-site.icon name="check" /></i>
                        <div>
                            <b>{{ $capability['name'] }}</b>
                            @if ($capability['description'])<span>{{ $capability['description'] }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="plan__cta">
            <a class="btn" href="{{ route('signup.create') }}">Apply for the pilot <x-site.icon name="arrow-right" :size="16" /></a>
            @unless ($compact ?? false)
                <a class="btn btn--ghost" href="{{ route('site.trust') }}">Review the security controls</a>
            @endunless
        </div>
        <p class="plan__fine">Submitting an application does not create an account, activate a licence or take payment. Allowances shown are those of the pilot plan today and may change.</p>
    </div>
@else
    <div class="empty">
        <b>Applications are not open right now.</b>
        <p style="margin-top:6px">No licence plan is currently available for signup. Please check back soon, or sign in if your organisation already has an account.</p>
        <p style="margin-top:16px"><a class="btn btn--ghost btn--sm" href="{{ route('login') }}">Sign in</a></p>
    </div>
@endif
