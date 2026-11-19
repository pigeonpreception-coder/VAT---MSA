{{-- Suggestions only: correction reason codes are free text. --}}
<datalist id="{{ $id }}">
    @foreach ($codes as $code)
        <option value="{{ $code }}">
    @endforeach
</datalist>
