<?php

namespace App\Filament\Restaurant\Pages;

use App\Enums\Permission;
use App\Filament\Schemas\PricingFields;
use App\Models\Restaurant;
use App\Models\RestaurantSetting;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use LogicException;

/**
 * Configuration for the one restaurant whose panel this is.
 *
 * The restaurant comes from the panel's tenant, which is the subdomain being
 * served, so this page can only ever read or write the settings of the
 * restaurant the visitor is already inside.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected string $view = 'filament.restaurant.pages.settings';

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
        $this->form->fill($this->readableCharges($this->settings()->attributesToArray()));
    }

    public function save(): void
    {
        $settings = $this->settings();

        $settings->fill($this->storableCharges($this->form->getState()))->save();

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

                Section::make('Charges')
                    ->schema([
                        // A switch rather than a rate that happens to be zero:
                        // a service charge is voluntary under the CCPA's 2022
                        // guidelines, so "we do not levy one" has to be sayable.
                        Toggle::make('service_charge_enabled')
                            ->label('Levy a service charge')
                            ->live()
                            ->inline(false),

                        TextInput::make('service_charge_percentage')
                            ->label('Service charge')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%')
                            ->required(fn (Get $get): bool => (bool) $get('service_charge_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('service_charge_enabled')),

                        Toggle::make('parcel_charge_enabled')
                            ->label('Charge for packing a takeaway')
                            ->live()
                            ->inline(false),

                        TextInput::make('parcel_charge')
                            ->label('Parcel charge')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999)
                            ->step(0.01)
                            ->prefix(fn (): string => PricingFields::currency()->symbol())
                            ->required(fn (Get $get): bool => (bool) $get('parcel_charge_enabled'))
                            ->visible(fn (Get $get): bool => (bool) $get('parcel_charge_enabled')),
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
        return sprintf('Configuration for %s.', $this->restaurant()->name);
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
     * Turn the stored rates and charges into the values the form edits.
     *
     * Each is stored the way the rest of the application stores its kind — the
     * two rates in basis points, the parcel charge in minor units like every
     * other amount of money — and each is typed here the way a person says it:
     * "5" percent, "10" percent and "20" rupees.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function readableCharges(array $data): array
    {
        $data['tax_rate_percentage'] = PricingFields::toPercentage(
            (int) ($data['tax_rate_basis_points'] ?? RestaurantSetting::DEFAULT_TAX_RATE_BASIS_POINTS),
        );

        $data['service_charge_percentage'] = PricingFields::toPercentage(
            (int) ($data['service_charge_basis_points'] ?? 0),
        );

        $data['parcel_charge'] = PricingFields::currency()
            ->toMajorUnits((int) ($data['parcel_charge_minor_units'] ?? 0));

        return $data;
    }

    /**
     * Turn the typed rates and charges back into what gets stored.
     *
     * The rounding happens here, once, so nothing downstream ever sees a float.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storableCharges(array $data): array
    {
        $data['tax_rate_basis_points'] = PricingFields::toBasisPoints($data['tax_rate_percentage'] ?? 0);

        $data['service_charge_basis_points'] = PricingFields::toBasisPoints(
            $data['service_charge_percentage'] ?? 0,
        );

        $data['parcel_charge_minor_units'] = PricingFields::currency()
            ->toMinorUnits($data['parcel_charge'] ?? 0);

        unset($data['tax_rate_percentage'], $data['service_charge_percentage'], $data['parcel_charge']);

        return $data;
    }

    /**
     * The settings of the restaurant whose panel this is, created on first view.
     */
    private function settings(): RestaurantSetting
    {
        $restaurant = $this->restaurant();

        // Remembered on the tenant, so filling the form, formatting a charge
        // and saving all read the one row once.
        $settings = $restaurant->resolvedSettings() ?? $restaurant->settings()->create([]);

        $restaurant->setRelation('settings', $settings);

        return $settings;
    }

    /**
     * The restaurant the panel is currently serving.
     */
    private function restaurant(): Restaurant
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Restaurant, LogicException::class, 'The restaurant settings page requires a restaurant tenant.');

        return $tenant;
    }
}
