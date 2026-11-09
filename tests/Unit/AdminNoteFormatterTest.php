<?php

namespace Tests\Unit;

use App\Support\AdminNoteFormatter;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class AdminNoteFormatterTest extends TestCase
{
    public function test_it_uses_the_pharmacist_display_name_in_admin_notes(): void
    {
        $user = (object) [
            'pharmacist_display_name' => 'Wasim Malik',
            'name' => 'Abhishek Paul',
            'email' => 'admin@example.test',
        ];

        $note = AdminNoteFormatter::format(
            'Patient contacted about treatment.',
            $user,
            Carbon::parse('2026-11-09 14:30:00'),
        );

        $this->assertSame(
            '09-11-2026 14:30 — Wasim Malik: Patient contacted about treatment.',
            $note,
        );
    }

    public function test_it_falls_back_to_the_account_name_when_no_pharmacist_name_exists(): void
    {
        $user = (object) [
            'pharmacist_display_name' => null,
            'name' => 'Admin User',
            'email' => 'admin@example.test',
        ];

        $this->assertSame('Admin User', AdminNoteFormatter::authorName($user));
    }

    public function test_it_appends_to_string_and_array_note_storage(): void
    {
        $this->assertSame(
            "Existing note\nNew note",
            AdminNoteFormatter::append('Existing note', 'New note'),
        );

        $this->assertSame(
            ['Existing note', 'New note'],
            AdminNoteFormatter::append(['Existing note'], 'New note'),
        );
    }
}
