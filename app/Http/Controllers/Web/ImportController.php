<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportFile;
use App\Models\Import;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function index(Request $request)
    {
        $imports = $request->user()
            ->imports()
            ->latest()
            ->paginate(15);

        return view('imports.index', compact('imports'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'],
            'chunk_size' => ['nullable', 'integer', 'min:10', 'max:5000'],
        ]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');

        $import = Import::create([
            'user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'chunk_size' => $request->input('chunk_size', 500),
        ]);

        ProcessImportFile::dispatch($import)->onQueue('imports');

        return redirect("/imports/{$import->id}")
            ->with('success', 'Файл загружен! Обработка началась.');
    }

    public function show(Import $import)
    {
        $this->authorize('view', $import);

        $failedRows = $import->failedRows()
            ->latest('row_number')
            ->paginate(20);

        return view('imports.show', compact('import', 'failedRows'));
    }

    public function retry(Import $import)
    {
        $this->authorize('update', $import);

        if ($import->failed_rows === 0) {
            return back()->with('error', 'Нет строк для повторной обработки.');
        }

        $failedData = $import->failedRows()
            ->pluck('original_data', 'row_number')
            ->toArray();

        $import->failedRows()->delete();
        $import->update([
            'status' => 'processing',
            'failed_rows' => 0,
            'processed_rows' => $import->processed_rows - count($failedData),
        ]);

        \App\Jobs\ProcessImportChunk::dispatch($import, $failedData, 0)
            ->onQueue('imports');

        return redirect("/imports/{$import->id}")
            ->with('success', 'Повторная обработка запущена.');
    }
}
