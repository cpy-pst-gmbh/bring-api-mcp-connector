<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BringCredential;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Updates the stored Bring! password without a full sign-in.
 *
 * Normally the copy is refreshed on every sign-in, so this only matters after
 * arriving through a login link — the case where the password changed at Bring!
 * and signing in the usual way no longer works.
 */
final class BringCredentialType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'account.password.label',
            'constraints' => [new NotBlank()],
            'help' => 'account.password.help',
            'attr' => ['autocomplete' => 'new-password'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BringCredential::class]);
    }
}
