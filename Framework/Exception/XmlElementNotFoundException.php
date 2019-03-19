<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Exception;

use Shopware\Core\Framework\ShopwareHttpException;

class XmlElementNotFoundException extends ShopwareHttpException
{
    public function __construct(string $element)
    {
        parent::__construct(
            'Unable to locate element with the name "{{ element }}".',
            ['element' => $element]
        );
    }

    public function getErrorCode(): string
    {
        return 'FRAMEWORK__XML_ELEMENT_NOT_FOUND';
    }
}
