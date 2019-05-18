<?php
declare(strict_types = 1);

namespace App\Controller;

use Krinkle\Intuition\Intuition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

abstract class ControllerBase extends AbstractController
{

    /** @var Intuition */
    protected $intuition;

    public function __construct(Intuition $intuition)
    {
        $this->intuition = $intuition;
    }

    /**
     * Get a translated message.
     * @param string $message The message name.
     * @param string[] $vars The message parameters.
     * @return string|null
     */
    protected function msg(string $message, ?array $vars = []): string
    {
        return $this->intuition->msg($message, ['variables' => $vars]);
    }
}
