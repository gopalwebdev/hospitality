<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\Permission;
use App\Filament\Schemas\PricingFields;
use App\Models\Tenant;
use App\Models\TenantSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use LogicException;

/**
 * Configuration for the one tenant whose panel this is.
 *
 * The tenant comes from the panel's tenant, which is the subdomain being
 * served, so this page can only ever read or write the settings of the
 * tenant the visitor is already inside.
 *
 * What is added on top of a bill is not here: charges have their own page, so a
 * tenant can keep as many as it levies and limit each to the menus it belongs
 * on. See App\Filament\Tenant\Resources\Charges\ChargeResource.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected string $view = 'filament.tenant.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill($this->readableRates($this->settings()->attributesToArray()));
    }

    public function save(): void
    {
        $settings = $this->settings();

        $settings->fill($this->storableRates($this->form->getState()))->save();

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->model($this->settings())
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->schema([
                        TextInput::make('contact_email')
                            ->label('Contact email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('contact_phone')
                            ->label('Contact phone')
                            ->tel()
                            ->maxLength(32),
                    ])
                    ->columns(2),

                Section::make('Trading')
                    ->schema([
                        TimePicker::make('opens_at')
                            ->label('Opens at')
                            ->seconds(false),
                        TimePicker::make('closes_at')
                            ->label('Closes at')
                            ->seconds(false),
                        Toggle::make('accepts_orders')
                            ->label('Accepting orders')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Tax')
                    ->schema([
                        TextInput::make('gstin')
                            ->label('GSTIN')
                            ->maxLength(15),

                        TextInput::make('tax_rate_percentage')
                            ->label('Default GST rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),

                        Toggle::make('prices_include_tax')
                            ->label('Menu prices already include GST')
                            ->inline(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())->key('form-actions'),
            ]);
    }

    public function getHeading(): string
    {
        return 'Settings';
    }

    public function getSubheading(): string
    {
        return sprintf('Configuration for %s.', $this->tenant()->name);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->submit('save'),
        ];
    }

    /**
     * Turn the stored GST rate into the value the form edits.
     *
     * Stored in basis points the way every rate is, and typed here the way an
     * accountant says it: "5" percent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function readableRates(array $data): array
    {
        $data['tax_rate_percentage'] = PricingFields::toPercentage(
            (int) ($data['tax_rate_basis_points'] ?? TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS),
        );

        return $data;
    }

    /**
     * Turn the typed GST rate back into what gets stored.
     *
     * The rounding happens here, once, so nothing downstream ever sees a float.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storableRates(array $data): array
    {
        $data['tax_rate_basis_points'] = PricingFields::toBasisPoints($data['tax_rate_percentage'] ?? 0);

        unset($data['tax_rate_percentage']);

        return $data;
    }

    /**
     * The settings of the tenant whose panel this is, created on first view.
     */
    private function settings(): TenantSetting
    {
        $tenant = $this->tenant();

        // Remembered on the tenant, so filling the form and saving both read
        // the one row once.
        $settings = $tenant->resolvedSettings() ?? $tenant->settings()->create([]);

        $tenant->setRelation('settings', $settings);

        return $settings;
    }

    /**
     * The tenant the panel is currently serving.
     */
    private function tenant(): Tenant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Tenant, LogicException::class, 'The tenant settings page requires a tenant.');

        return $tenant;
    }
}
