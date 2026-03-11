<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    private function userId(): int
    {
        return auth()->id();
    }

    public function mount(): void
    {
        $userId = $this->userId();

        $this->form->fill([
            'jules_api_key' => Setting::getValue('jules_api_key', '', $userId),
            'github_token' => Setting::getValue('github_token', '', $userId),
            'github_webhook_secret' => Setting::getValue('github_webhook_secret', '', $userId),
            'gemini_api_key' => Setting::getValue('gemini_api_key', '', $userId),
            'ai_provider' => Setting::getValue('ai_provider', 'gemini', $userId),
            'lmstudio_base_url' => Setting::getValue('lmstudio_base_url', 'http://localhost:1234', $userId),
            'lmstudio_model' => Setting::getValue('lmstudio_model', '', $userId),
            'gemini_model' => Setting::getValue('gemini_model', 'gemini-2.0-flash', $userId),
            'auto_fix_threshold' => Setting::getValue('auto_fix_threshold', 'warning', $userId),
            'auto_fix_enabled' => Setting::getValue('auto_fix_enabled', 'true', $userId) === 'true',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Settings')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Jules API')
                            ->icon('heroicon-o-sparkles')
                            ->schema([
                                Forms\Components\TextInput::make('jules_api_key')
                                    ->label('Jules API Key')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Get your API key from [jules.google.com/settings](https://jules.google.com/settings#api)')
                                    ->placeholder('Your Jules API Key'),
                            ]),

                        Forms\Components\Tabs\Tab::make('GitHub')
                            ->icon('heroicon-o-code-bracket')
                            ->schema([
                                Forms\Components\TextInput::make('github_token')
                                    ->label('Personal Access Token')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Needs `repo` and `pull_request:write` scopes')
                                    ->placeholder('ghp_xxxxxxxxxxxx'),

                                Forms\Components\Section::make('Webhook')
                                    ->description('All repositories share this webhook configuration')
                                    ->schema([
                                        Forms\Components\Placeholder::make('webhook_url')
                                            ->label('Webhook URL')
                                            ->content(url('/api/webhooks/github'))
                                            ->helperText('Add this URL as a webhook in your GitHub repository settings. Select "Pull requests" events.'),

                                        Forms\Components\TextInput::make('github_webhook_secret')
                                            ->label('Webhook Secret')
                                            ->password()
                                            ->revealable()
                                            ->helperText('Use this same secret for all GitHub webhook configurations.')
                                            ->suffixAction(
                                                Forms\Components\Actions\Action::make('regenerate_webhook_secret')
                                                    ->icon('heroicon-o-arrow-path')
                                                    ->action(function (Forms\Set $set) {
                                                        $set('github_webhook_secret', \Illuminate\Support\Str::random(40));
                                                    })
                                            ),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('AI Review Engine')
                            ->icon('heroicon-o-cpu-chip')
                            ->schema([
                                Forms\Components\Select::make('ai_provider')
                                    ->label('AI Provider')
                                    ->options([
                                        'gemini' => '🌟 Google Gemini',
                                        'lmstudio' => '🖥️ LM Studio (Local)',
                                    ])
                                    ->default('gemini')
                                    ->live()
                                    ->helperText('Select the AI engine for code reviews'),

                                Forms\Components\Section::make('Gemini Settings')
                                    ->schema([
                                        Forms\Components\TextInput::make('gemini_api_key')
                                            ->label('Gemini API Key')
                                            ->password()
                                            ->revealable()
                                            ->helperText('Get your key from [aistudio.google.com](https://aistudio.google.com/)')
                                            ->placeholder('AIzaSy...'),

                                        Forms\Components\TextInput::make('gemini_model')
                                            ->label('Model')
                                            ->default('gemini-2.0-flash')
                                            ->helperText('e.g., gemini-2.0-flash, gemini-1.5-pro'),
                                    ])
                                    ->visible(fn (Forms\Get $get) => $get('ai_provider') === 'gemini'),

                                Forms\Components\Section::make('LM Studio Settings')
                                    ->schema([
                                        Forms\Components\TextInput::make('lmstudio_base_url')
                                            ->label('Base URL')
                                            ->default('http://localhost:1234')
                                            ->helperText('LM Studio server base URL (default: http://localhost:1234)'),

                                        Forms\Components\TextInput::make('lmstudio_model')
                                            ->label('Model Name')
                                            ->placeholder('Leave blank for default loaded model')
                                            ->helperText('The model identifier loaded in LM Studio'),
                                    ])
                                    ->visible(fn (Forms\Get $get) => $get('ai_provider') === 'lmstudio'),
                            ]),

                        Forms\Components\Tabs\Tab::make('Auto-Fix')
                            ->icon('heroicon-o-wrench-screwdriver')
                            ->schema([
                                Forms\Components\Toggle::make('auto_fix_enabled')
                                    ->label('Enable Auto-Fix via Jules')
                                    ->helperText('When enabled, Jules will automatically create fix PRs for issues found'),

                                Forms\Components\Select::make('auto_fix_threshold')
                                    ->label('Auto-Fix Severity Threshold')
                                    ->options([
                                        'critical' => '🚨 Critical only',
                                        'warning' => '⚠️ Warning and above',
                                    ])
                                    ->default('warning')
                                    ->helperText('Minimum severity level to trigger Jules auto-fix'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $userId = $this->userId();

        Setting::setValue('jules_api_key', $data['jules_api_key'] ?? '', 'jules', 'Jules API Key', $userId);
        Setting::setValue('github_token', $data['github_token'] ?? '', 'github', 'GitHub Personal Access Token', $userId);
        Setting::setValue('github_webhook_secret', $data['github_webhook_secret'] ?? '', 'github', 'GitHub Webhook Secret', $userId);
        Setting::setValue('gemini_api_key', $data['gemini_api_key'] ?? '', 'gemini', 'Gemini API Key', $userId);
        Setting::setValue('ai_provider', $data['ai_provider'] ?? 'gemini', 'general', 'AI Provider', $userId);
        Setting::setValue('lmstudio_base_url', $data['lmstudio_base_url'] ?? 'http://localhost:1234', 'general', 'LM Studio Base URL', $userId);
        Setting::setValue('lmstudio_model', $data['lmstudio_model'] ?? '', 'general', 'LM Studio Model', $userId);
        Setting::setValue('gemini_model', $data['gemini_model'] ?? 'gemini-2.0-flash', 'general', 'Gemini Model', $userId);
        Setting::setValue('auto_fix_threshold', $data['auto_fix_threshold'] ?? 'warning', 'general', 'Auto-Fix Threshold', $userId);
        Setting::setValue('auto_fix_enabled', ($data['auto_fix_enabled'] ?? false) ? 'true' : 'false', 'general', 'Auto-Fix Enabled', $userId);

        Notification::make()
            ->title('Settings saved successfully')
            ->success()
            ->send();
    }
}
