

@extends('filament::page')

@section('title', 'Edit Consultation Form')

@section('content')
<div class="min-h-screen bg-gray-900 p-6 space-y-6">
    <div class="bg-gray-800 rounded-lg p-6"> {{-- removed border & shadow --}}
        <h1 class="text-xl font-semibold text-white mb-4">Edit Consultation Form</h1>
        <p class="text-gray-400 text-sm mb-6">
            Use this form to modify the submitted consultation data for review or correction.
        </p>

        @if(session('success'))
            <div class="bg-green-700/20 border border-green-600 text-green-300 p-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.consultations.forms.edit', ['session' => $session->id ?? 0, 'form' => $form->id ?? 0]) }}">
            @csrf
            <!-- fields... -->
        </form>
    </div>
</div>
@endsection