<?php
// src/Plugins/Billing/Entity/BillingPlanEntity.php

namespace App\Plugins\Billing\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: "App\Plugins\Billing\Repository\BillingPlanRepository")]
#[ORM\Table(name: "billing_plans")]
class BillingPlanEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private int $id;

    #[ORM\Column(type: "string", length: 50)]
    private string $name;

    #[ORM\Column(type: "string", length: 50, unique: true)]
    private string $slug;

    #[ORM\Column(name: "price_monthly", type: "integer")]
    private int $priceMonthly = 0;

    #[ORM\Column(name: "included_seats", type: "integer")]
    private int $includedSeats = 1;

    #[ORM\Column(name: "stripe_product_id", type: "string", length: 255, nullable: true)]
    private ?string $stripeProductId = null;

    #[ORM\Column(name: "stripe_price_id", type: "string", length: 255, nullable: true)]
    private ?string $stripePriceId = null;

    #[ORM\Column(name: "created_at", type: "datetime")]
    private \DateTime $createdAt;

    #[ORM\Column(name: "updated_at", type: "datetime")]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getPriceMonthly(): int
    {
        return $this->priceMonthly;
    }

    public function getIncludedSeats(): int
    {
        return $this->includedSeats;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description ?? null,
            'price_monthly' => $this->priceMonthly,
            'included_seats' => $this->includedSeats,
            'stripe_price_id' => $this->stripePriceId,
            'stripe_product_id' => $this->stripeProductId,
            'is_active' => $this->isActive ?? true,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}