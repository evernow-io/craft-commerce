<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use craft\commerce\models\Coupon;
use Craft;
use craft\commerce\db\Table;
use craft\commerce\records\Coupon as CouponRecord;
use craft\db\Query;
use craft\helpers\StringHelper;
use Exception;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Coupons service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 4.0
 *
 * @property-read null|array $allCodes
 */
class Coupons extends Component
{
    public const COUPON_FORMAT_REPLACEMENT_CHAR = '#';
    public const DEFAULT_COUPON_FORMAT = '######';
    public const CHARS_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    public const CHARS_LOWER = 'abcdefghijklmnopqrstuvwxyz';
    public const CHARS_NUMBERS = '0123456789';
    public const CHARS_SPECIAL = '!@#$%^&*()-_=+[]{}|;:,.<>/?~';

    /**
     * @var array|null
     */
    private ?array $_allCodes = null;

    /**
     * @return array|null
     */
    public function getAllCodes(): ?array
    {
        if ($this->_allCodes !== null) {
            return $this->_allCodes;
        }

        $this->_allCodes = $this->_createCouponQuery()
            ->indexBy('id')
            ->select(['coupons.code'])
            ->column();

        return $this->_allCodes;
    }

    /**
     * @param string $code
     * @return Coupon|null
     * @throws InvalidConfigException
     */
    public function getCouponByCode(string $code): ?Coupon
    {
        $coupon = $this->_createCouponQuery()
            ->where(['code' => $code])
            ->one();

        return $coupon ? Craft::createObject(Coupon::class, ['config' => ['attributes' => $coupon]]) : null;
    }

    /**
     * @param int $discountId
     * @return Coupon[]
     * @throws InvalidConfigException
     */
    public function getCouponsByDiscountId(int $discountId): array
    {
        $coupons = $this->_createCouponQuery()
            ->where(['discountId' => $discountId])
            ->all();

        foreach ($coupons as &$coupon) {
            $coupon = Craft::createObject(Coupon::class, ['config' => ['attributes' => $coupon]]);
        }

        return $coupons;
    }

    /**
     * @param int $count
     * @param string $format
     * @param array $existingCodes
     * @return string[]
     * @throws Exception
     */
    public function generateCouponCodes(int $count = 1, string $format = self::DEFAULT_COUPON_FORMAT, array $existingCodes = []): array
    {
        // Count the number of # characters in the format
        $numReplacementChars = strlen($format) - strlen(str_replace(self::COUPON_FORMAT_REPLACEMENT_CHAR, '', $format));
        $numPossibleCodes = strlen(self::CHARS_UPPER) ** $numReplacementChars;

        if ($numPossibleCodes < $count) {
            // TODO figure out correct exception to throw
            throw new \Exception('The format is too restrictive to generate enough unique codes.');
        }

        $existingCodes = array_unique([...$existingCodes, ...$this->getAllCodes()]);
        $coupons = [];

        for ($i = 1; $i <= $count; $i++) {
            $code = preg_replace_callback('/(['.self::COUPON_FORMAT_REPLACEMENT_CHAR.']+)/', static function ($matches) {
                $length = strlen($matches[0]);
                return StringHelper::randomStringWithChars(self::CHARS_UPPER, $length);
            }, $format);

            if (!empty($existingCodes) && in_array($code, $existingCodes, true)) {
                $i--;
                continue;
            }
            $coupons[] = $code;
            $existingCodes[] = $code;
        }

        return $coupons;
    }

    /**
     * @param Coupon $coupon
     * @param bool $runValidation
     * @return bool
     * @throws Exception
     */
    public function saveCoupon(Coupon $coupon, bool $runValidation = true): bool
    {
        if ($coupon->id) {
            $record = CouponRecord::findOne($coupon->id);

            if (!$record) {
                throw new Exception(Craft::t('commerce', 'No coupon exists with the ID “{id}”', ['id' => $coupon->id]));
            }
        } else {
            $record = new CouponRecord();
        }

        if ($runValidation && !$coupon->validate()) {
            Craft::info('Coupon not saved due to validation error.', __METHOD__);

            return false;
        }

        $record->code = $coupon->code;
        $record->discountId = $coupon->discountId;
        $record->uses = $coupon->uses;
        $record->maxUses = $coupon->maxUses;

        // Save it!
        $record->save(false);

        // Now that we have a record ID, save it on the model
        $coupon->id = $record->id;

        $this->clearCache();

        return true;
    }

    /**
     * @return void
     */
    protected function clearCache(): void
    {
        $this->_allCodes = null;
    }

    /**
     * Returns a Query object prepped for retrieving Coupons.
     *
     * @return Query The query object.
     */
    private function _createCouponQuery(): Query
    {
        return (new Query())
            ->select([
                'coupons.id',
                'coupons.code',
                'coupons.uses',
                'coupons.maxUses',
                'coupons.discountId',
            ])
            ->from([Table::COUPONS . ' coupons']);
    }
}
