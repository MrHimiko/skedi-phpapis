<?php

namespace App\Plugins\Integrations\Common\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: "integration_rate_limits")]
#[ORM\UniqueConstraint(columns: ["integration_id", "endpoint", "window_start"])]
#[ORM\Index(columns: ["integration_id", "endpoint"])]
#[ORM\Index(columns: ["window_start"])]
class IntegrationRateLimitEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(name: "integration_id", type: "bigint")]
    private int $integrationId;

    #[ORM\Column(name: "endpoint", type: "string", length: 255)]
    private string $endpoint;

    #[ORM\Column(name: "requests_count", type: "integer", options: ["default" => 0])]
    private int $requestsCount = 0;

    #[ORM\Column(name: "window_start", type: "datetime")]
    private DateTimeInterface $windowStart;

    #[ORM\Column(name: "created", type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->created = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIntegrationId(): int
    {
        return $this->integrationId;
    }

    public function setIntegrationId(int $integrationId): self
    {
        $this->integrationId = $integrationId;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getRequestsCount(): int
    {
        return $this->requestsCount;
    }

    public function setRequestsCount(int $requestsCount): self
    {
        $this->requestsCount = $requestsCount;
        return $this;
    }

    public function incrementRequestsCount(): self
    {
        $this->requestsCount++;
        return $this;
    }

    public function getWindowStart(): DateTimeInterface
    {
        return $this->windowStart;
    }

    public function setWindowStart(DateTimeInterface $windowStart): self
    {
        $this->windowStart = $windowStart;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }
}