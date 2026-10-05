{{-- Administration pages are English-only, never indexed and never cached. --}}
@extends('layouts.app', ['noindex' => true])
@section('title', trim($__env->yieldContent('admin-title')).' — Administration')
@section('body-class', 'page-admin')

@section('content')
    <nav class="card card-body mb-4 p-2" aria-label="Administration">
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.dashboard')) active @endif" href="{{ route('admin.dashboard') }}">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.works.*')) active @endif" href="{{ route('admin.works.index') }}">Works</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.editions.*')) active @endif" href="{{ route('admin.editions.index') }}">Editions</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.contributors.*')) active @endif" href="{{ route('admin.contributors.index') }}">Contributors</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.taxonomy.*')) active @endif" href="{{ route('admin.taxonomy.index') }}">Categories &amp; collections</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.csv.*')) active @endif" href="{{ route('admin.csv.create') }}">CSV import</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.reports.*')) active @endif" href="{{ route('admin.reports.index') }}">Reports</a></li>
            <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.audit')) active @endif" href="{{ route('admin.audit') }}">Audit log</a></li>
            @if (auth()->user()->isAdmin())
                <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.languages.*')) active @endif" href="{{ route('admin.languages.index') }}">Languages</a></li>
                <li class="nav-item"><a class="nav-link @if(request()->routeIs('admin.users.*')) active @endif" href="{{ route('admin.users.index') }}">Users</a></li>
            @endif
        </ul>
    </nav>

    <h1>@yield('admin-title')</h1>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @yield('admin')
@endsection
