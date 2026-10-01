<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\License;

/**
 * Interface LicenseCheckInterface
 *
 * Provides the premium feature license state to runtime integrations.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
interface LicenseCheckInterface
{
    /**
     * Returns whether premium functionality is licensed.
     *
     * @return bool License state.
     */
    public function isValid(): bool;
}
