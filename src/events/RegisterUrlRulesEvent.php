<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\app\events;

/**
 * RegisterUrlRulesEvent class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class RegisterUrlRulesEvent extends Event
{
    // Properties
    // =========================================================================

    /**
     * @var array The registered URL rules.
     */
    public $rules = [];
}