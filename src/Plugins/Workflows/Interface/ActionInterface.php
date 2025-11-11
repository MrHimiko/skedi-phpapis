<?php

namespace App\Plugins\Workflows\Interface;

interface ActionInterface
{
    /**
     * Get unique action identifier (e.g., 'email.send', 'webhook.send')
     */
    public function getId(): string;

    /**
     * Get human-readable action name (e.g., 'Send Email')
     */
    public function getName(): string;

    /**
     * Get action description
     */
    public function getDescription(): string;

    /**
     * Get action category (e.g., 'communication', 'integration')
     */
    public function getCategory(): string;

    /**
     * Get icon identifier (e.g., 'PhEnvelope')
     */
    public function getIcon(): string;

    /**
     * Get configuration schema for the action
     * Returns array of field definitions for the UI
     */
    public function getConfigSchema(): array;

    /**
     * Execute the action
     * 
     * @param array $config Action configuration from workflow
     * @param array $context Context data (booking, event, etc.)
     * @return array Result of execution with 'success' key
     */
    public function execute(array $config, array $context): array;

    /**
     * Validate action configuration
     * 
     * @param array $config Configuration to validate
     * @return array Array of error messages (empty if valid)
     */
    public function validate(array $config): array;
}