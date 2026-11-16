<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkJournalEntry extends Model
{
    protected $fillable = ['work_journal_id', 'time', 'location', 'task', 'note', 'sort'];
}
