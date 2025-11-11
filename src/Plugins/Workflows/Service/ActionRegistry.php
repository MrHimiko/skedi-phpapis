<?php

namespace App\Plugins\Workflows\Service;

use App\Plugins\Workflows\Interface\ActionInterface;

class ActionRegistry
{
    private array $actions = [];

    /**
     * Constructor - inject all actions that implement ActionInterface
     * Symfony will automatically inject all tagged services
     */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $action) {
            if ($action instanceof ActionInterface) {
                $this->register($action);
            }
        }
    }

    /**
     * Register an action
     */
    public function register(ActionInterface $action): void
    {
        $this->actions[$action->getId()] = $action;
    }

    /**
     * Get action by ID
     */
    public function getAction(string $id): ?ActionInterface
    {
        return $this->actions[$id] ?? null;
    }

    /**
     * Get all registered actions
     * 
     * @return ActionInterface[]
     */
    public function getAllActions(): array
    {
        return $this->actions;
    }

    /**
     * Get actions grouped by category
     */
    public function getActionsByCategory(): array
    {
        $categorized = [];

        foreach ($this->actions as $action) {
            $category = $action->getCategory();
            if (!isset($categorized[$category])) {
                $categorized[$category] = [];
            }
            $categorized[$category][] = [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'description' => $action->getDescription(),
                'icon' => $action->getIcon(),
                'config_schema' => $action->getConfigSchema()
            ];
        }

        return $categorized;
    }

    /**
     * Get actions as array for API response
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->actions as $action) {
            $result[] = [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'description' => $action->getDescription(),
                'category' => $action->getCategory(),
                'icon' => $action->getIcon(),
                'config_schema' => $action->getConfigSchema()
            ];
        }

        return $result;
    }
}