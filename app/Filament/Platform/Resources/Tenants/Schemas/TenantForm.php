<?php

namespace App\Filament\Platform\Resources\Tenants\Schemas;

use App\Enums\CountryCallingCode;
use App\Enums\Role as RoleEnum;
use App\Enums\TenantType;
use App\Models\Tenant;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        // One column of full-width sections, read top to bottom in the order a
        // tenant is actually set up: what it is called, how big its roster
        // may get, where it trades, and how to reach it. A two-column grid of
        // sections put the address beside the name, which read as two unrelated
        // starting points rather than one sequence.
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identity')
                    ->description('What this tenant is called, and where it is served from.')
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::OutlinedIdentification)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $set('slug', Str::slug((string) $state));
                            }),

                        // The slug is the tenant's subdomain, so it has to stay a valid
                        // DNS label: lowercase alphanumerics and inner hyphens only.
                        TextInput::make('slug')
                            ->label('Subdomain')
                            ->required()
                            ->maxLength(63)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/')
                            ->prefixIcon(Heroicon::OutlinedGlobeAlt)
                            ->suffix(fn (): string => '.'.config('app.domain'))
                            ->helperText('Lowercase letters, numbers and hyphens. This becomes the address guests scan into.')
                            ->validationMessages([
                                'regex' => 'Use lowercase letters, numbers and hyphens only.',
                            ]),

                        // Three buttons, each drawn with its own icon, rather than a
                        // dropdown: with three choices, seeing them all is one tap
                        // fewer than opening a list. No default, so onboarding has to
                        // choose; editable afterwards.
                        ToggleButtons::make('type')
                            ->options(TenantType::options())
                            ->icons(self::typeIcons())
                            ->enum(TenantType::class)
                            ->inline()
                            ->grouped()
                            ->required()
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Open for business')
                            ->default(true)
                            ->onIcon(Heroicon::Check)
                            ->offIcon(Heroicon::XMark)
                            ->onColor('success')
                            ->offColor('gray')
                            ->helperText('Turning this off takes the storefront offline.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Limits')
                    ->description('How many accounts may hold each role here. An admin sets these; the tenant cannot raise its own.')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->schema([
                        TextInput::make('max_owners')
                            ->label('Max owners')
                            ->prefixIcon(Heroicon::OutlinedShieldCheck)
                            ->suffix('accounts')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(fn (): int => (int) config('tenants.default_max_owners'))
                            ->required()
                            ->helperText('At least one tenant owner is required.')
                            ->rule(fn (?Tenant $record): Closure => self::notBelowCurrentHolders($record, RoleEnum::Owner)),

                        TextInput::make('max_staff')
                            ->label('Max staff')
                            ->prefixIcon(Heroicon::OutlinedUsers)
                            ->suffix('accounts')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(fn (): int => (int) config('tenants.default_max_staff'))
                            ->required()
                            ->helperText('How many floor staff accounts this tenant may have at once.')
                            ->rule(fn (?Tenant $record): Closure => self::notBelowCurrentHolders($record, RoleEnum::Staff)),
                    ])
                    ->columns(2),

                Section::make('Where it trades')
                    ->description('The address of the business itself.')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->schema([
                        TextInput::make('address')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::OutlinedMapPin)
                            ->columnSpan(2),

                        TextInput::make('pincode')
                            ->label('Pincode')
                            ->required()
                            ->maxLength(16)
                            ->prefixIcon(Heroicon::OutlinedHashtag)
                            ->inputMode('numeric'),
                    ])
                    ->columns(3),

                // The platform's own record of how to reach whoever runs this
                // tenant. What guests see is on the tenant's settings
                // page, which the tenant edits itself.
                Section::make('How the platform reaches them')
                    ->description('Not shown to guests: the storefront contact details are on the tenant’s own settings page.')
                    ->icon(Heroicon::OutlinedPhone)
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->prefixIcon(Heroicon::OutlinedEnvelope)
                            ->columnSpanFull(),

                        // Stored as two columns: the calling code, and the
                        // national number on its own. Only India is served for
                        // now, so the code is a one-option select rather than
                        // something to type wrongly. Each pair sits on one line
                        // where there is room for it and stacks where there is
                        // not: a phone is too narrow to give the code and the
                        // number a box apiece.
                        Grid::make(['default' => 1, '@sm' => 5])
                            ->gridContainer()
                            ->schema([
                                Select::make('phone_country_code')
                                    ->label('Country code')
                                    ->options(CountryCallingCode::options())
                                    ->default(CountryCallingCode::India->value)
                                    ->selectablePlaceholder(false)
                                    ->prefixIcon(Heroicon::OutlinedFlag)
                                    ->required()
                                    ->columnSpan(['@sm' => 2]),

                                TextInput::make('phone')
                                    ->label('Mobile number')
                                    ->tel()
                                    ->required()
                                    ->prefixIcon(Heroicon::OutlinedDevicePhoneMobile)
                                    ->rule('digits:'.CountryCallingCode::India->mobileNumberLength())
                                    ->maxLength(CountryCallingCode::longestMobileNumberLength())
                                    ->helperText(sprintf('%d digits, without the country code.', CountryCallingCode::India->mobileNumberLength()))
                                    ->columnSpan(['@sm' => 3]),
                            ]),

                        Grid::make(['default' => 1, '@sm' => 5])
                            ->gridContainer()
                            ->schema([
                                Select::make('secondary_phone_country_code')
                                    ->label('Secondary country code')
                                    ->options(CountryCallingCode::options())
                                    ->prefixIcon(Heroicon::OutlinedFlag)
                                    ->requiredWith('secondary_phone')
                                    // A code with no number behind it says nothing, so
                                    // it is stored only while there is one.
                                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => filled($get('secondary_phone')) ? $state : null)
                                    ->columnSpan(['@sm' => 2]),

                                TextInput::make('secondary_phone')
                                    ->label('Secondary mobile number')
                                    ->tel()
                                    ->prefixIcon(Heroicon::OutlinedDevicePhoneMobile)
                                    ->rule('digits:'.CountryCallingCode::India->mobileNumberLength())
                                    ->maxLength(CountryCallingCode::longestMobileNumberLength())
                                    ->columnSpan(['@sm' => 3]),
                            ]),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Each type's icon, keyed by stored value, for the type buttons.
     *
     * @return array<string, Heroicon>
     */
    private static function typeIcons(): array
    {
        return array_reduce(
            TenantType::cases(),
            static function (array $icons, TenantType $type): array {
                $icons[$type->value] = $type->icon();

                return $icons;
            },
            [],
        );
    }

    /**
     * Refuse a limit lower than the roster it would already break.
     *
     * A tenant with 3 staff may not be dropped to a limit of 2 — the panel
     * says how many to remove first rather than silently locking the extra
     * ones out of a role they still hold. $record is null while creating,
     * where a fresh tenant has no roster yet to break.
     */
    private static function notBelowCurrentHolders(?Tenant $record, RoleEnum $role): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($record, $role): void {
            if (! $record instanceof Tenant) {
                return;
            }

            $current = $record->roleHolderCount($role);
            $limit = (int) $value;

            if ($current <= $limit) {
                return;
            }

            $noun = $role === RoleEnum::Owner
                ? ($current === 1 ? 'owner' : 'owners')
                : ($current === 1 ? 'staff member' : 'staff members');

            $fail(sprintf(
                'This tenant has %d %s. Remove %d before lowering the limit to %d.',
                $current,
                $noun,
                $current - $limit,
                $limit,
            ));
        };
    }
}
