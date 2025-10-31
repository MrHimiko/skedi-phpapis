<?php

namespace App\Plugins\Account\Entity;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Doctrine\ORM\Mapping as ORM;

use DateTimeInterface;
use DateTime;

#[ORM\Entity(repositoryClass: "App\Plugins\Account\Repository\UserRepository")]
#[ORM\Table(name: "users")]
class UserEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: "id", type: "integer")]
    private int $id;

    #[ORM\Column(name: "full_name", type: "string", length: 255)]
    private string $name;

    #[ORM\Column(name: "email", type: "string", length: 255, unique: true)]
    private string $email;

    #[ORM\Column(name: "password", type: "string", length: 255, nullable: true)]
    private ?string $password;

    #[ORM\Column(name: "email_verified", type: "boolean", options: ["default" => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: "email_verified_at", type: "datetime", nullable: true)]
    private ?DateTimeInterface $emailVerifiedAt = null;

    #[ORM\Column(name: "updated", type: "datetime", nullable: true)]
    private ?DateTimeInterface $updated = null;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "deleted", type: "boolean", options: ["default" => false])]
    private bool $deleted = false;

    public function __construct()
    {
        $this->updated = new DateTime(); 
        $this->created = new DateTime(); 
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getInitials(): string
    {
        $name = explode(' ' , $this->name);

        if(count($name) >= 2)
        {
            return $name[0][0] . '' . $name[1][0];
        }

        return $this->name[0];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): self
    {
        $this->emailVerified = $emailVerified;
        if ($emailVerified && !$this->emailVerifiedAt) {
            $this->emailVerifiedAt = new DateTime();
        }
        return $this;
    }

    public function getEmailVerifiedAt(): ?DateTimeInterface
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?DateTimeInterface $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    public function setUpdated(?DateTimeInterface $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(DateTimeInterface $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;
        return $this;
    }
   
    public function toArray(): array
    {
        return [
            'id'           => $this->getId(),
            'name'         => $this->getName(),
            'initials'     => $this->getInitials(),
            'email'        => $this->getEmail(),
            'email_verified' => $this->isEmailVerified(),
            'updated'      => $this->getUpdated()?->format('Y-m-d H:i:s'),
            'created'      => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
    }
}