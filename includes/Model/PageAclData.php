<?php

namespace Isekai\LitePageACL\Model;

class PageAclData {
	public int $pageId;
	public ?int $ownerActorId = null;
	public bool $inherit = false;
	public int $aclVersion = 0;

	/** @var array<int,array{actor_id:int,roles:string[],permissions:string[]}> */
	public array $grants = [];

	public static function empty( int $pageId ): self {
		$data = new self();
		$data->pageId = $pageId;
		return $data;
	}

	public function toArray(): array {
		return [
			'page_id' => $this->pageId,
			'owner_actor_id' => $this->ownerActorId,
			'inherit' => $this->inherit,
			'acl_version' => $this->aclVersion,
			'grants' => array_values( $this->grants ),
		];
	}
}
