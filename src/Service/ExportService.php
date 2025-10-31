<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class ExportService
{
    /**
     * Export data to CSV format
     * 
     * @param array $filters
     * @return StreamedResponse
     */
    public function exportToCsv(array $filters = []): StreamedResponse
    {
        $data = $this->getExportData($filters);
        $columns = $this->getExportColumns();
        $filename = $this->getFilename();
        
        $response = new StreamedResponse();
        $response->setCallback(function() use ($data, $columns) {
            $handle = fopen('php://output', 'w+');
            
            // Add UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Add headers
            fputcsv($handle, array_values($columns));
            
            // Add data rows
            foreach ($data as $row) {
                $csvRow = [];
                foreach (array_keys($columns) as $key) {
                    $csvRow[] = $this->formatValue($row, $key);
                }
                fputcsv($handle, $csvRow);
            }
            
            fclose($handle);
        });
        
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        
        return $response;
    }
    
    /**
     * Format a value for CSV export
     */
    protected function formatValue($row, $key): string
    {
        $value = $this->getNestedValue($row, $key);
        
        if (is_null($value)) {
            return '';
        }
        
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return (string) $value;
    }
    
    /**
     * Get nested value using dot notation
     */
    protected function getNestedValue($data, $key)
    {
        $keys = explode('.', $key);
        $value = $data;
        
        foreach ($keys as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } elseif (is_object($value)) {
                if (method_exists($value, 'get' . ucfirst($k))) {
                    $value = $value->{'get' . ucfirst($k)}();
                } elseif (property_exists($value, $k)) {
                    $value = $value->$k;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
        
        return $value;
    }
    
    /**
     * Get data to export
     * 
     * @param array $filters
     * @return array
     */
    abstract protected function getExportData(array $filters): array;
    
    /**
     * Get column definitions
     * Format: ['key' => 'Header Label']
     * 
     * @return array
     */
    abstract protected function getExportColumns(): array;
    
    /**
     * Get export filename
     * 
     * @return string
     */
    abstract protected function getFilename(): string;
}