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

    public function mount(): void
    {
        $this->form->fill([
            'jules_api_key' => Setting::getValue('jules_api_key', ''),
            'github_token' => Setting::getValue('github_token', ''),
            'gemini_api_key' => Setting::getValue('gemini_api_key', ''),
            'ai_provider' => Setting::getValue('ai_provider', 'gemini'),
            'lmstudio_base_url' => Setting::getValue('lmstudio_base_url', 'http://localhost:1234'),
            'lmstudio_model' => Setting::getValue('lmstudio_model', ''),
            'gemini_model' => Setting::getValue('gemini_model', 'gemini-2.0-flash'),
            'auto_fix_threshold' => Setting::getValue('auto_fix_threshold', 'warning'),
            'auto_fix_enabled' => Setting::getValue('auto_fix_enabled', 'true') === 'true',
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

                                Forms\Components\Placeholder::make('webhook_url')
                                    ->label('Webhook URL')
                                    ->content(url('/api/webhooks/github'))
                                    ->helperText('Add this URL as a webhook in your GitHub repository settings. Select "Pull requests" events.'),
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
                                    ->visible(fn(Forms\Get $get) => $get('ai_provider') === 'gemini'),

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
                                    ->visible(fn(Forms\Get $get) => $get('ai_provider') === 'lmstudio'),
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

        // Save each setting
        Setting::setValue('jules_api_key', $data['jules_api_key'] ?? '', 'jules', 'Jules API Key');
        Setting::setValue('github_token', $data['github_token'] ?? '', 'github', 'GitHub Personal Access Token');
        Setting::setValue('gemini_api_key', $data['gemini_api_key'] ?? '', 'gemini', 'Gemini API Key');
        Setting::setValue('ai_provider', $data['ai_provider'] ?? 'gemini', 'general', 'AI Provider');
        Setting::setValue('lmstudio_base_url', $data['lmstudio_base_url'] ?? 'http://localhost:1234', 'general', 'LM Studio Base URL');
        Setting::setValue('lmstudio_model', $data['lmstudio_model'] ?? '', 'general', 'LM Studio Model');
        Setting::setValue('gemini_model', $data['gemini_model'] ?? 'gemini-2.0-flash', 'general', 'Gemini Model');
        Setting::setValue('auto_fix_threshold', $data['auto_fix_threshold'] ?? 'warning', 'general', 'Auto-Fix Threshold');
        Setting::setValue('auto_fix_enabled', ($data['auto_fix_enabled'] ?? false) ? 'true' : 'false', 'general', 'Auto-Fix Enabled');

        Notification::make()
            ->title('Settings saved successfully')
            ->success()
            ->send();
    }
}
