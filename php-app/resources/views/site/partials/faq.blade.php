@php
    // Every answer states only what the platform does today, including the
    // pilot caveats the product itself shows.
    $faqs = [
        ['q' => 'What is VAT-MSA?', 'a' => [
            'VAT-MSA is an e-VAT management platform for Namibia. Businesses submit invoices, which are validated and certified. The platform then supports VAT returns, supplier and customer reconciliation, and the oversight workflows a tax authority needs: risk indicators, audit cases, obligations, disputes and refunds.',
        ]],
        ['q' => 'Who is it for?', 'a' => [
            'Registered businesses that issue and receive VAT invoices; the tax-authority staff who oversee them; and software or POS providers who connect billing systems through the API. Each group works in its own role-based view of the same data.',
        ]],
        ['q' => 'How does invoice certification work?', 'a' => [
            'Your billing system sends an invoice through the API. VAT-MSA checks the totals, the currency, the VAT category on every line and that the supplier is authorised to issue it. A valid invoice receives a unique fingerprint, a certificate and a verification link with a QR payload to print on the invoice.',
            'Submitting the same invoice twice never creates a duplicate.',
        ]],
        ['q' => 'How does a customer verify an invoice?', 'a' => [
            'They scan the QR code or open the verification link, or paste the code on the <a href="'.e(route('site.verify')).'">Verify page</a>. No account is needed. They see the certificate\'s status, the supplier, a masked invoice number and the gross amount, plus any corrections. Line items and customer details are never shown.',
        ]],
        ['q' => 'Are the certificates legally binding?', 'a' => [
            'Not yet. VAT-MSA is in pilot, and certificates issued during the pilot are pilot certificates. Production legal signatures require an approved signing profile and protected signing keys, which are not part of the pilot.',
        ]],
        ['q' => 'How do I get access?', 'a' => [
            'Submit an <a href="'.e(route('signup.create')).'">access request</a> with your organisation\'s VAT number, TIN and details. Administrators verify the application, and only then is a licence activated. Submitting the form does not create an account or take payment.',
        ]],
        ['q' => 'Is my data secure?', 'a' => [
            'Access is role-based and scoped, sensitive actions need a fresh authenticator-app code, every significant action is written to a tamper-evident audit trail, and public endpoints are rate limited. The <a href="'.e(route('site.trust')).'">security overview</a> lists the controls in detail.',
        ]],
        ['q' => 'Can I connect my ERP or POS?', 'a' => [
            'Yes. Scoped API clients let a billing system or POS terminal submit invoices, and there is a dedicated POS endpoint. Credentials can be rotated and revoked, and conformance runs help you test an integration before relying on it.',
        ]],
        ['q' => 'Does VAT-MSA connect to government systems?', 'a' => [
            'The connector framework for authority and customs systems exists, but connectors stay disabled until a technical contract is confirmed with the system owner. Until then VAT-MSA does not pull, or invent, data from those systems.',
        ]],
    ];
@endphp
@foreach ($faqs as $faq)
    <details>
        <summary>
            <span>{{ $faq['q'] }}</span>
            <span class="plus"><x-site.icon name="plus" :size="16" /></span>
        </summary>
        <div class="answer">
            @foreach ($faq['a'] as $paragraph)
                <p>{!! $paragraph !!}</p>
            @endforeach
        </div>
    </details>
@endforeach
