<?php
/**
 * IndexController.php
 * Copyright (c) 2018 thegrumpydictator@gmail.com
 *
 * This file is part of Firefly III.
 *
 * Firefly III is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Firefly III is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Firefly III. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Http\Controllers\Budget;


use Carbon\Carbon;
use Exception;
use FireflyIII\Http\Controllers\Controller;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Log;
use Preferences;
use View;

/**
 *
 * Class IndexController
 */
class IndexController extends Controller
{

    /** @var BudgetRepositoryInterface */
    private $repository;

    /**
     *
     */
    public function __construct()
    {
        parent::__construct();

        View::share('hideBudgets', true);

        $this->middleware(
            function ($request, $next) {
                app('view')->share('title', trans('firefly.budgets'));
                app('view')->share('mainTitleIcon', 'fa-tasks');
                $this->repository = app(BudgetRepositoryInterface::class);

                return $next($request);
            }
        );
    }


    /**
     * @param Request     $request
     * @param string|null $moment
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request, string $moment = null)
    {
        $range       = Preferences::get('viewRange', '1M')->data;
        $start       = session('start', new Carbon);
        $end         = session('end', new Carbon);
        $page        = 0 === (int)$request->get('page') ? 1 : (int)$request->get('page');
        $pageSize    = (int)Preferences::get('listPageSize', 50)->data;
        $days        = 0;
        $daysInMonth = 0;

        // make date if present:
        if (null !== $moment || '' !== (string)$moment) {
            try {
                $start = new Carbon($moment);
                $end   = app('navigation')->endOfPeriod($start, $range);
            } catch (Exception $e) {
                // start and end are already defined.
                Log::debug(sprintf('start and end are already defined: %s', $e->getMessage()));
            }
        }

        // if today is between start and end, use the diff in days between end and today (days left)
        // otherwise, use diff between start and end.
        $today = new Carbon;
        if ($today->gte($start) && $today->lte($end)) {
            $days        = $end->diffInDays($today);
            $daysInMonth = $start->diffInDays($today);
        }
        if ($today->lte($start) || $today->gte($end)) {
            $days        = $start->diffInDays($end);
            $daysInMonth = $start->diffInDays($end);
        }
        $days        = 0 === $days ? 1 : $days;
        $daysInMonth = 0 === $daysInMonth ? 1 : $daysInMonth;


        $next = clone $end;
        $next->addDay();
        $prev = clone $start;
        $prev->subDay();
        $prev = app('navigation')->startOfPeriod($prev, $range);
        $this->repository->cleanupBudgets();
        $allBudgets        = $this->repository->getActiveBudgets();
        $total             = $allBudgets->count();
        $budgets           = $allBudgets->slice(($page - 1) * $pageSize, $pageSize);
        $inactive          = $this->repository->getInactiveBudgets();
        $periodStart       = $start->formatLocalized($this->monthAndDayFormat);
        $periodEnd         = $end->formatLocalized($this->monthAndDayFormat);
        $budgetInformation = $this->repository->collectBudgetInformation($allBudgets, $start, $end);
        $defaultCurrency   = app('amount')->getDefaultCurrency();
        $available         = $this->repository->getAvailableBudget($defaultCurrency, $start, $end);
        $spent             = array_sum(array_column($budgetInformation, 'spent'));
        $budgeted          = array_sum(array_column($budgetInformation, 'budgeted'));

        // paginate budgets
        $budgets = new LengthAwarePaginator($budgets, $total, $pageSize, $page);
        $budgets->setPath(route('budgets.index'));

        // select thing for last 12 periods:
        $previousLoop = [];
        /** @var Carbon $previousDate */
        $previousDate = clone $start;
        $count        = 0;
        while ($count < 12) {
            $previousDate->subDay();
            $previousDate          = app('navigation')->startOfPeriod($previousDate, $range);
            $format                = $previousDate->format('Y-m-d');
            $previousLoop[$format] = app('navigation')->periodShow($previousDate, $range);
            ++$count;
        }

        // select thing for next 12 periods:
        $nextLoop = [];
        /** @var Carbon $nextDate */
        $nextDate = clone $end;
        $nextDate->addDay();
        $count = 0;

        while ($count < 12) {
            $format            = $nextDate->format('Y-m-d');
            $nextLoop[$format] = app('navigation')->periodShow($nextDate, $range);
            $nextDate          = app('navigation')->endOfPeriod($nextDate, $range);
            ++$count;
            $nextDate->addDay();
        }

        // display info
        $currentMonth = app('navigation')->periodShow($start, $range);
        $nextText     = app('navigation')->periodShow($next, $range);
        $prevText     = app('navigation')->periodShow($prev, $range);

        return view(
            'budgets.index', compact(
                               'available', 'currentMonth', 'next', 'nextText', 'prev', 'allBudgets', 'prevText', 'periodStart', 'periodEnd', 'days', 'page',
                               'budgetInformation', 'daysInMonth',
                               'inactive', 'budgets', 'spent', 'budgeted', 'previousLoop', 'nextLoop', 'start', 'end'
                           )
        );
    }

}