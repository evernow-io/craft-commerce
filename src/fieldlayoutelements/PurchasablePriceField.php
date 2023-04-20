<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\base\Purchasable;
use craft\commerce\helpers\Purchasable as PurchasableHelper;
use craft\commerce\Plugin;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use yii\base\InvalidArgumentException;

/**
 * PurchasablePriceField represents a Prie field that is included within a variant field layout designer.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 5.0.0
 */
class PurchasablePriceField extends BaseNativeField
{
    /**
     * @inheritdoc
     */
    public bool $mandatory = true;

    /**
     * @inheritdoc
     */
    public ?string $label = '__blank__';

    /**
     * @inheritdoc
     */
    public bool $required = true;

    /**
     * @inheritdoc
     */
    public string $attribute = 'price';

    /**
     * @inheritdoc
     */
    public function inputHtml(ElementInterface $element = null, bool $static = false): ?string
    {
        $view = Craft::$app->getView();

        if (!$element instanceof Purchasable) {
            throw new InvalidArgumentException(static::class . ' can only be used in purchasable field layouts.');
        }

        $basePrice = $element->basePrice;
        if (empty($element->getErrors('basePrice'))) {
            if ($basePrice === null) {
                $basePrice = 0;
            }

            $basePrice = Craft::$app->getFormatter()->asDecimal($basePrice);
        }

        $basePromotionalPrice = $element->basePromotionalPrice;
        if (empty($element->getErrors('basePromotionalPrice')) && $basePromotionalPrice !== null) {
            $basePromotionalPrice = Craft::$app->getFormatter()->asDecimal($basePromotionalPrice);
        }

        $priceNamespace = $view->namespaceInputName('basePrice');
        $promotionalPriceNamespace = $view->namespaceInputName('basePromotionalPrice');
        $priceListContainer = $view->namespaceInputId('purchasable-prices');

        $title = Json::encode($element->title);

        $js = <<<JS
(() => {
    if (typeof initPurchasablePriceList === 'undefined') {
        let initPurchasablePriceList = function() {
            let _priceFields = $('input[name="$priceNamespace"], input[name="$promotionalPriceNamespace"]');
            const _newButton = $('button.js-cpr-slideout-new');
            const _editLink = $('a.js-purchasable-cpr-slideout');
            
            const getPriceList = function(_el) {
                const _tableContainer = _el.parents('.js-purchasable-price-field').find('.js-price-list-container');
                const _loadingElements = _tableContainer.find('.js-prices-table-loading');
                _loadingElements.removeClass('hidden');
                console.log('asd');
              Craft.sendActionRequest('POST', 'commerce/catalog-pricing/generate-catalog-prices', {
                    data: {
                        purchasableId: $element->id,
                        storeId: $element->storeId,
                        basePrice: $('input[name="$priceNamespace"]').val(),
                        basePromotionalPrice: $('input[name="$promotionalPriceNamespace"]').val(),
                    }
                })
                .then((response) => {
                    _loadingElements.addClass('hidden');
                    if (response.data) {
                        $('#$priceListContainer .tableview').replaceWith(response.data);
                    }
                    _priceFields.off('change');
                    _newButton.off('click');
                    _editLink.off('click');
                    
                    initPurchasablePriceList();
                })
                .catch(({response}) => {
                    _loadingElements.addClass('hidden');
                    if (response.data && response.data.message) {
                        Craft.cp.displayError(response.data.message);
                    }
                    _priceFields.off('change');
                    _newButton.off('click');
                    _editLink.off('click');
                    
                    initPurchasablePriceList();
                });
            };
            
            _priceFields.on('change', function(e) {               
                getPriceList($(this));
            });
            
            // New catalog price
            _newButton.on('click', function(e) {
                e.preventDefault();
                let _this = $(this);
                let slideout = new Craft.CpScreenSlideout('commerce/catalog-pricing-rules/slideout', {
                    params: {
                        storeId: $element->storeId,
                        purchasableId: $element->id,
                        title: $title,
                    }
                });
                slideout.on('submit', function({response, data}) {
                    getPriceList(_this);
                });
            });
            
            // Edit catalog price        
            _editLink.on('click', function(e) {
                e.preventDefault();
                let _this = $(this);
                let slideout = new Craft.CpScreenSlideout('commerce/catalog-pricing-rules/slideout', {
                    params: {
                        id: _this.data('id'),
                        storeId: _this.data('store-id'),
                        purchasableId: $element->id,
                    }
                });
                slideout.on('submit', function({response, data}) {
                    getPriceList(_this);
                });
            });
        }
        
        initPurchasablePriceList();
    } else {
        initPurchasablePriceList();
    }
})();
JS;
        $view->registerJs($js);

        return Html::beginTag('div', ['class' => 'js-purchasable-price-field']) .
            Html::beginTag('div', ['class' => 'flex']) .
                Cp::textFieldHtml([
                    'id' => 'base-price',
                    'label' => Craft::t('commerce', 'Price') . sprintf('(%s)', Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso()),
                    'name' => 'basePrice',
                    'value' => $basePrice,
                    'placeholder' => Craft::t('commerce', 'Enter price'),
                    'required' => true,
                    'errors' => $element->getErrors('basePrice'),
                ]) .
                Cp::textFieldHtml([
                    'id' => 'promotional-price',
                    'label' => Craft::t('commerce', 'Promotional Price') . sprintf('(%s)', Plugin::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso()),
                    'name' => 'basePromotionalPrice',
                    'value' => $basePromotionalPrice,
                    'placeholder' => Craft::t('commerce', 'Enter price'),
                    'errors' => $element->getErrors('basePromotionalPrice'),
                ]) .
            Html::endTag('div') .
            Html::beginTag('div') .
                Html::tag('div',
                    Html::tag('a', 'See all prices', ['class' => 'fieldtoggle', 'data-target' => 'purchasable-prices']) .
                    Html::beginTag('div', ['class' => 'js-price-list-container', 'style' => ['position' => 'relative']]) .
                    Html::tag(
                        'div',
                        // Prices table
                        PurchasableHelper::catalogPricingRulesTableByPurchasableId($element->id, $element->storeId) .
                        // New catalog price button
                        Html::button(Craft::t('commerce', 'Add catalog price'), ['class' => 'btn icon add js-cpr-slideout-new', 'data-icon' => 'plus']),
                        [
                            'id' => 'purchasable-prices',
                            'class' => 'hidden'
                        ]
                    ) .
                    Html::tag('div', '', [
                        'class' => 'js-prices-table-loading hidden',
                        'style' => [
                            'position' => 'absolute',
                            'top' => 0,
                            'left' => 0,
                            'width' => '100%',
                            'height' => '100%',
                            'background-color' => 'rgba(255, 255, 255, 0.5)',
                        ]
                    ]) .
                    Html::tag('div', Html::tag('span', '', ['class' => 'spinner']), [
                        'class' => 'js-prices-table-loading flex hidden',
                        'style' => [
                            'position' => 'absolute',
                            'top' => 0,
                            'left' => 0,
                            'width' => '100%',
                            'height' => '100%',
                            'align-items' => 'center',
                            'justify-content' => 'center',
                        ]
                    ]) .
                    Html::endTag('div')
                ).
            Html::endTag('div') .
        Html::endTag('div');
    }
}
