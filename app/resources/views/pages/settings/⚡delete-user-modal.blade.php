<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        {{-- Apilados y a ancho completo en móvil; en línea y a la derecha desde sm. --}}
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <flux:modal.close class="w-full sm:w-auto">
                <flux:button variant="filled" class="min-h-11 w-full">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" class="min-h-11" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
