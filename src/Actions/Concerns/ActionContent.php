<?php

namespace Rmsramos\Activitylog\Actions\Concerns;

use Closure;
use Filament\Actions\StaticAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Rmsramos\Activitylog\Infolists\Components\TimeLineIconEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLinePropertiesEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLineRepeatableEntry;
use Rmsramos\Activitylog\Infolists\Components\TimeLineTitleEntry;
use Spatie\Activitylog\Models\Activity;

trait ActionContent
{
    private ?array $withRelations = null;

    private ?array $timelineIcons = null;

    private ?array $timelineIconColors = null;

    private ?int $limit = 10;

    protected Closure $modifyQueryUsing;

    protected Closure|Builder $query;

    protected ?Closure $activitiesUsing;

    protected ?Closure $modifyTitleUsing;

    protected ?Closure $shouldModifyTitleUsing;

    public static function getDefaultName(): ?string
    {
        return 'activitylog_timeline';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInfolist();
        $this->configureModal();
        $this->activitiesUsing        = null;
        $this->modifyTitleUsing       = null;
        $this->shouldModifyTitleUsing = fn() => true;
        $this->modifyQueryUsing       = fn($builder) => $builder;
        $this->modalHeading           = __('activitylog::action.modal.heading');
        $this->modalDescription       = __('activitylog::action.modal.description');
        $this->query                  = function (?Model $record) {
            return Activity::query()
                ->where(function (Builder $query) use ($record) {
                    $query->where(function (Builder $q) use ($record) {
                        $q->where('subject_type', $record->getMorphClass())
                            ->where('subject_id', $record->getKey());
                    })->when($this->getWithRelations(), function (Builder $query, array $relations) use ($record) {
                        foreach ($relations as $relation) {
                            $model = get_class($record->{$relation}()->getRelated());
                            $query->orWhere(function (Builder $q) use ($record, $model, $relation) {
                                $q->where('subject_type', (new $model)->getMorphClass())
                                    ->whereIn('subject_id', $record->{$relation}()->pluck('id'));
                            });
                        }
                    });
                });
        };
    }

    private function configureInfolist(): void
    {
        $this->infolist(function (?Model $record, Infolist $infolist) {
            return $infolist
                ->state(['activities' => $this->getActivityLogRecord($record, $this->getWithRelations())])
                ->schema($this->getSchema());
        });
    }

    private function configureModal(): void
    {
        $this->slideOver()
            ->modalIcon('heroicon-o-eye')
            ->modalFooterActions(fn() => [])
            ->tooltip(__('activitylog::action.modal.tooltip'))
            ->icon('heroicon-o-bell-alert');
    }

    private function getSchema(): array
    {
        return [
            TimeLineRepeatableEntry::make('activities')
                ->schema([
                    TimeLineIconEntry::make('activityData.event')
                        ->icon(function ($state) {
                            return $this->getTimelineIcons()[$state] ?? 'heroicon-m-check';
                        })
                        ->color(function ($state) {
                            return $this->getTimelineIconColors()[$state] ?? 'primary';
                        }),
                    TimeLineTitleEntry::make('activityData')
                        ->configureTitleUsing($this->modifyTitleUsing)
                        ->shouldConfigureTitleUsing($this->shouldModifyTitleUsing),
                    TimeLinePropertiesEntry::make('activityData'),
                    TextEntry::make('updated_at')
                        ->hiddenLabel()
                        ->since()
                        ->badge(),
                ]),
        ];
    }

    public function withRelations(?array $relations = null): ?StaticAction
    {
        $this->withRelations = $relations;

        return $this;
    }

    public function getWithRelations(): ?array
    {
        return $this->evaluate($this->withRelations);
    }

    public function timelineIcons(?array $timelineIcons = null): ?StaticAction
    {
        $this->timelineIcons = $timelineIcons;

        return $this;
    }

    public function getTimelineIcons(): ?array
    {
        return $this->evaluate($this->timelineIcons);
    }

    public function timelineIconColors(?array $timelineIconColors = null): ?StaticAction
    {
        $this->timelineIconColors = $timelineIconColors;

        return $this;
    }

    public function getTimelineIconColors(): ?array
    {
        return $this->evaluate($this->timelineIconColors);
    }

    public function limit(?int $limit = 10): ?StaticAction
    {
        $this->limit = $limit;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->evaluate($this->limit);
    }

    public function query(Closure|Builder|null $query): static
    {
        $this->query = $query;

        return $this;
    }

    public function getQuery(): ?Builder
    {
        return $this->evaluate($this->query);
    }

    public function modifyQueryUsing(Closure $closure): static
    {
        $this->modifyQueryUsing = $closure;

        return $this;
    }

    public function getModifyQueryUsing(Builder $builder): Builder
    {
        $this->evaluate($this->modifyQueryUsing, ['builder' => $builder]);

        return $builder;
    }

    public function modifyTitleUsing(Closure $closure): static
    {
        $this->modifyTitleUsing = $closure;

        return $this;
    }

    public function shouldModifyTitleUsing(Closure $closure): static
    {
        $this->shouldModifyTitleUsing = $closure;

        return $this;
    }

    public function activitiesUsing(Closure $closure): static
    {
        $this->activitiesUsing = $closure;

        return $this;
    }

    public function getActivitiesUsing(): ?Collection
    {
        return $this->evaluate($this->activitiesUsing);
    }

    protected function getActivities(?Model $record, ?array $relations = null): Collection
    {
        if ($activities = $this->getActivitiesUsing()) {
            return $activities;
        } else {
            $builder = $this->getQuery()
                ->latest()
                ->limit($this->getLimit());
            $this->getModifyQueryUsing($builder);

            return $builder
                ->get();
        }
    }

    protected function getActivityLogRecord(?Model $record, ?array $relations = null): Collection
    {
        $activities = $this->getActivities($record, $relations);

        foreach ($activities as $activity) {
            $activity;
        }

        return $activities->transform(function ($activity) {
            $activity->activityData = $this->formatActivityData($activity);

            return $activity;
        });
    }

    protected function formatActivityData(Activity $activity): array
    {
        $properties = $activity->getChangesAttribute();



        return [
            'log_name'    => $activity->log_name,
            'description' => $activity->description,
            'subject'     => $activity->subject,
            'event'       => $activity->event,
            'properties'  => $this->prepareFormatData($properties->toArray()),
            'batch_uuid'  => $activity->batch_uuid,
            'update'      => $activity->updated_at,
        ];
    }

    function prepareFormatData($properties)
    {
        $formattedData = [];

        if (isset($properties['attributes'])) {
            $attributes = $properties['attributes'];
            $oldAttributes = $properties['old'] ?? [];

            foreach ($attributes as $key => $value) {
                switch ($key) {
                    case 'highlights':
                        $formattedData['new_names'] = $value;
                        break;
                    case 'app_description':
                        $formattedData['new_description'] = $value;
                        break;
                    case 'short_description':
                        $formattedData['old_description'] = $oldAttributes[$key] ?? null;
                        $formattedData['new_description'] = $value;
                        break;
                    case 'app_name':
                        $formattedData['old_name'] = $oldAttributes[$key] ?? null;
                        $formattedData['new_name'] = $value;
                        break;
                    default:
                        $formattedData[$key] = $value;
                        break;
                }
            }
        }

        return $formattedData;
    }
}
