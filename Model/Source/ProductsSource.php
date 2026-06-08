<?php
declare(strict_types=1);

namespace Etechflow\MegaMenu\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for "Featured Products Source" — controls which products fill the
 * right side of each mega-menu dropdown.
 *
 * The value is read in {@see \Etechflow\MegaMenu\Controller\Products\Index}
 * and applied to the product collection ordering/filter.
 */
class ProductsSource implements OptionSourceInterface
{
    public const POSITION    = 'position';
    public const NEWEST      = 'newest';
    public const BESTSELLERS = 'bestsellers';
    public const ON_SALE     = 'on_sale';
    public const PRICE_ASC   = 'price_asc';
    public const PRICE_DESC  = 'price_desc';

    /**
     * @return array<int,array{value:string,label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::POSITION,    'label' => __('Category Position (default)')],
            ['value' => self::NEWEST,      'label' => __('Newest First')],
            ['value' => self::BESTSELLERS, 'label' => __('Best Sellers')],
            ['value' => self::ON_SALE,     'label' => __('On Sale (has special price)')],
            ['value' => self::PRICE_ASC,   'label' => __('Price: Low to High')],
            ['value' => self::PRICE_DESC,  'label' => __('Price: High to Low')],
        ];
    }
}
