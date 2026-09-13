@extends('layouts.app')

@section('title', $title)

@section('content')
<x-planned-module :eyebrow="$eyebrow" :title="$title" :description="$description" :scope-note="$scopeNote" />
@endsection
