@props(['title' => null])

{{-- Validation / action errors after a form redirect. One message reads as a
     sentence; several get a short heading and a list. --}}
@if ($errors->any())
    @php $messages = $errors->all(); @endphp
    <div {{ $attributes->merge(['class' => 'alert alert-danger']) }} role="alert">
        <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
        <div>
            @if (count($messages) === 1)
                @if ($title)<strong>{{ $title }}</strong> @endif{{ $messages[0] }}
            @else
                <strong>{{ $title ?? 'Please check the following:' }}</strong>
                <ul class="mb-0 ps-3 mt-1">
                    @foreach ($messages as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif
