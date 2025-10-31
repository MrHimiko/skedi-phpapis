<?php

namespace App\Service;

class SlugService
{
    public function generateSlug(string $string): string
    {
        $slug = strtolower(trim($string));
        $slug = preg_replace('/[^a-z0-9\s]+/', '', $slug);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        
        return $slug;
    }
}
