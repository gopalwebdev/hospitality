<?php

namespace App\Filament\Tenant\Pages;

use App\Enums\Permission;
use App\Enums\Weekday;
use App\Filament\Forms\Components\ClockTimePicker;
use App\Filament\Schemas\PricingFields;
use App\Models\Tenant;
use App\Models\TenantOpeningHour;
use App\Models\TenantSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * Configuration for the one tenant whose panel this is.
 *
 * The tenant comes from the panel's tenant, which is the subdomain being
 * served, so this page can only ever read or write the settings of the
 * tenant the visitor is already inside.
 *
 * Three sections: how to reach the tenant, the GST its prices are read against,
 * and the week its doors keep. Hours are a row per weekday
 * (`tenant_opening_hours`) rather than one pair of times, because a tenant
 * keeps different hours on different days and shuts on one of them.
 *
 * What is added on top of a bill is not here: charges have their own page, so a
 * tenant can keep as many as it levies and limit each to the menus it belongs
 * on. See App\Filament\Tenant\Resources\Charges\ChargeResource.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    #[\Override]
    protected string $view = 'filament.tenant.pages.settings';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    #[\Override]
    protected static ?int $navigationSort = 90;

    /** What a day opens and closes at until a tenant says otherwise. */
    private const string DEFAULT_OPENS_AT = '09:00';

    private const string DEFAULT_CLOSES_AT = '23:00';

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
        $settings = $this->settings()->attributesToArray();

        $this->form->fill([
            ...$this->readableRates($settings),
            'hours' => $this->readableHours(),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        /** @var array<string, array<string, mixed>> $hours */
        $hours = $state['hours'] ?? [];
        unset($state['hours']);

        $this->settings()->fill($this->storableRates($state))->save();
        $this->storeHours($hours);

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
                // How to reach the tenant and what it charges, side by side
                // where the page has room and stacked where it has not. The
                // week needs the full width, so it sits underneath.
                Grid::make(['default' => 1, '@4xl' => 2])
                    ->gridContainer()
                    ->schema([
                        $this->contactSection(),
                        $this->taxSection(),
                    ]),

                $this->openingHoursSection(),
            ]);
    }

    /**
     * Where a guest or the platform reaches this tenant.
     */
    private function contactSection(): Section
    {
        return Section::make('Contact')
            ->icon(Heroicon::OutlinedEnvelope)
            ->compact()
            ->columns(2)
            ->schema([
                TextInput::make('contact_email')
                    ->label('Contact email')
                    ->email()
                    ->maxLength(255)
                    ->prefixIcon(Heroicon::OutlinedEnvelope)
                    ->columnSpanFull(),

                TextInput::make('contact_phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(32)
                    ->prefixIcon(Heroicon::OutlinedPhone),

                TextInput::make('alternate_phone')
                    ->label('Alternate phone')
                    ->tel()
                    ->maxLength(32)
                    ->prefixIcon(Heroicon::OutlinedPhone),

                TextInput::make('landline_phone')
                    ->label('Landline')
                    ->tel()
                    ->maxLength(32)
                    ->prefixIcon(Heroicon::OutlinedPhoneArrowUpRight)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The GST every price is read against, in the two halves it is levied in.
     */
    private function taxSection(): Section
    {
        return Section::make('Tax')
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->compact()
            ->columns(2)
            ->schema([
                TextInput::make('gstin')
                    ->label('GSTIN')
                    ->maxLength(15)
                    ->columnSpanFull(),

                $this->halfRate('cgst_rate_percentage', 'CGST'),
                $this->halfRate('sgst_rate_percentage', 'SGST'),

                // The two added up, which is what anything is actually taxed
                // at — worked out as it is typed rather than typed again.
                Text::make(fn (Get $get): string => sprintf('GST in all: %s', PricingFields::formatRate($this->typedRate($get))))
                    ->weight(FontWeight::Medium)
                    ->columnSpanFull(),

                Toggle::make('tax_overrides_item_rates')
                    ->label('Tax every item at this rate')
                    ->columnSpanFull(),

                Toggle::make('prices_include_tax')
                    ->label('Menu prices already include GST')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * One half of the rate, typed the way an accountant quotes it.
     */
    private function halfRate(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%')
            // Live, because the two of them are added up above as they are typed.
            ->live(onBlur: true);
    }

    /**
     * The week the doors keep: a row per day, closed or open between two times.
     */
    private function openingHoursSection(): Section
    {
        return Section::make('Opening hours')
            ->icon(Heroicon::OutlinedClock)
            ->compact()
            ->schema(array_map(
                fn (Weekday $weekday): Grid => $this->dayRow($weekday, $weekday === Weekday::Monday),
                Weekday::week(),
            ));
    }

    /**
     * One day of the week, as a row of a table that has its headings on the first row alone.
     */
    private function dayRow(Weekday $weekday, bool $isFirst): Grid
    {
        $path = "hours.{$weekday->value}";
        $isOpen = fn (Get $get): bool => ! (bool) $get("{$path}.is_closed");

        return Grid::make(['default' => 2, '@md' => 4])
            ->schema([
                Text::make($weekday->label())
                    ->weight(FontWeight::Medium),

                Toggle::make("{$path}.is_closed")
                    ->label('Closed')
                    ->hiddenLabel(! $isFirst)
                    ->inline(false)
                    // Live: the two times below are only asked for on a day that opens.
                    ->live(),

                ClockTimePicker::make("{$path}.opens_at")
                    ->label('Opens')
                    ->hiddenLabel(! $isFirst)
                    ->visible($isOpen)
                    ->required($isOpen),

                ClockTimePicker::make("{$path}.closes_at")
                    ->label('Closes')
                    ->hiddenLabel(! $isFirst)
                    ->visible($isOpen)
                    ->required($isOpen),
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
     * What the two halves come to, from what is typed rather than what is stored.
     */
    private function typedRate(Get $get): int
    {
        return PricingFields::toBasisPoints($get('cgst_rate_percentage') ?? 0)
            + PricingFields::toBasisPoints($get('sgst_rate_percentage') ?? 0);
    }

    /**
     * Turn the stored GST halves into the values the form edits.
     *
     * Stored in basis points the way every rate is, and typed here the way an
     * accountant says it: "2.5" percent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function readableRates(array $data): array
    {
        $half = intdiv(TenantSetting::DEFAULT_TAX_RATE_BASIS_POINTS, 2);

        $data['cgst_rate_percentage'] = PricingFields::toPercentage((int) ($data['cgst_rate_basis_points'] ?? $half));
        $data['sgst_rate_percentage'] = PricingFields::toPercentage((int) ($data['sgst_rate_basis_points'] ?? $half));

        return $data;
    }

    /**
     * Turn the typed GST halves back into what gets stored.
     *
     * The rounding happens here, once, so nothing downstream ever sees a float.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function storableRates(array $data): array
    {
        $data['cgst_rate_basis_points'] = PricingFields::toBasisPoints($data['cgst_rate_percentage'] ?? 0);
        $data['sgst_rate_basis_points'] = PricingFields::toBasisPoints($data['sgst_rate_percentage'] ?? 0);

        unset($data['cgst_rate_percentage'], $data['sgst_rate_percentage']);

        return $data;
    }

    /**
     * The week as the form edits it, with a day nobody has set yet offered open.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readableHours(): array
    {
        $stored = $this->tenant()->resolvedOpeningHours();
        $hours = [];

        foreach (Weekday::week() as $weekday) {
            $day = $stored->get($weekday->value);

            // A day with no row yet is offered open at the usual hours, and a
            // day that is closed keeps them too, so turning the toggle back off
            // leaves the pickers on something sensible rather than on nothing.
            $hours[$weekday->value] = $day instanceof TenantOpeningHour
                ? [
                    'is_closed' => $day->is_closed,
                    'opens_at' => $day->opensAt() ?? self::DEFAULT_OPENS_AT,
                    'closes_at' => $day->closesAt() ?? self::DEFAULT_CLOSES_AT,
                ]
                : [
                    'is_closed' => false,
                    'opens_at' => self::DEFAULT_OPENS_AT,
                    'closes_at' => self::DEFAULT_CLOSES_AT,
                ];
        }

        return $hours;
    }

    /**
     * Write the week back, one row per day, and forget the hours of a day that is closed.
     *
     * @param  array<string, array<string, mixed>>  $hours
     */
    private function storeHours(array $hours): void
    {
        $tenant = $this->tenant();

        foreach (Weekday::week() as $weekday) {
            $day = $hours[$weekday->value] ?? [];
            $isClosed = (bool) ($day['is_closed'] ?? false);

            // Through the relation, which sets the tenant itself: `tenant_id`
            // is not fillable on a child row anywhere in this codebase.
            $tenant->openingHours()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'is_closed' => $isClosed,
                    'opens_at' => $isClosed ? null : ($day['opens_at'] ?? self::DEFAULT_OPENS_AT),
                    'closes_at' => $isClosed ? null : ($day['closes_at'] ?? self::DEFAULT_CLOSES_AT),
                ],
            );
        }

        // Read again next time rather than from the copy this page filled with.
        $tenant->unsetRelation('openingHours');
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
