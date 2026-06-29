<?php

namespace Isekai\LitePageACL\Model;

class PermissionDecision {
	private bool $allowed;
	private ?string $reason;

	public function __construct( bool $allowed, ?string $reason = null ) {
		$this->allowed = $allowed;
		$this->reason = $reason;
	}

	public function allow(): void {
		$this->allowed = true;
		$this->reason = null;
	}

	public function deny( ?string $reason = null ): void {
		$this->allowed = false;
		$this->reason = $reason;
	}

	public function isAllowed(): bool {
		return $this->allowed;
	}

	public function getReason(): ?string {
		return $this->reason;
	}
}
