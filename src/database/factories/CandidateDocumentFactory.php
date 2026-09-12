<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\CandidateDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateDocument>
 */
class CandidateDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'document_type' => 'transcript',
            'original_name' => 'transcript.pdf',
            'file_path' => 'candidate-documents/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ];
    }
}
