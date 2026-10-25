@php
    $contactEmail = config('site.contact_email');
    $operator = config('site.operator');
@endphp
<footer class="site-footer">
    <div class="wrap">
        <div class="site-footer__grid">
            <div class="site-footer__about">
                <a class="brand" href="{{ route('site.home') }}" aria-label="VAT-MSA home"><x-site.logo :inverse="true" /></a>
                <p>e-VAT management for Namibia: invoice certification, reconciliation and oversight in one controlled platform.</p>
                <p class="status" data-status hidden>
                    <span class="status__dot" aria-hidden="true"></span>
                    <span data-status-text>All systems operational</span>
                </p>
            </div>

            <nav aria-labelledby="footer-product">
                <h2 id="footer-product">Product</h2>
                <ul>
                    <li><a href="{{ route('site.product') }}">Platform overview</a></li>
                    <li><a href="{{ route('site.product') }}#certification">Invoice certification</a></li>
                    <li><a href="{{ route('site.product') }}#reconciliation">Reconciliation</a></li>
                    <li><a href="{{ route('site.product') }}#oversight">Compliance &amp; oversight</a></li>
                    <li><a href="{{ route('site.product') }}#integrations">Integrations</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="footer-trust">
                <h2 id="footer-trust">Trust</h2>
                <ul>
                    <li><a href="{{ route('site.trust') }}">Security overview</a></li>
                    <li><a href="{{ route('site.trust') }}#pilot">Pilot status</a></li>
                    <li><a href="{{ route('site.verify') }}">Verify an invoice</a></li>
                    @if ($contactEmail)
                        <li><a href="mailto:{{ $contactEmail }}">Contact</a></li>
                    @endif
                </ul>
            </nav>

            <nav aria-labelledby="footer-access">
                <h2 id="footer-access">Access</h2>
                <ul>
                    <li><a href="{{ route('signup.create') }}">Request access</a></li>
                    <li><a href="{{ route('login') }}">Sign in</a></li>
                    <li><a href="{{ route('site.plans') }}">Plans</a></li>
                    <li><a href="{{ route('password.request') }}">Reset password</a></li>
                </ul>
            </nav>
        </div>

        <div class="site-footer__legal">
            <p>&copy; {{ date('Y') }} {{ $operator ?: 'VAT-MSA' }}. All rights reserved.</p>
            <p>VAT-MSA is in pilot. Certificates issued during the pilot are pilot certificates; production legal signatures require an approved signing profile and protected signing keys.</p>
        </div>
    </div>
</footer>
